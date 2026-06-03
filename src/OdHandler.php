<?php

class OdfHandler extends BitmapHandler {

    public function canRender( $file ) {
        return true;
    }

    public function getThumbType( $ext, $mime, $params = null ) {
        return [ 'png', 'image/png' ];
    }

    // 1. Lettura Metadati compatibile sia con percorsi locali che con mwstore://
    public function getSizeAndMetadata( $state, $path ) {
        $localPath = $path;
        $isTmp = false;

        // Se il percorso è virtuale (mwstore://), ZipArchive fallirebbe. 
        // Chiediamo a MediaWiki una copia locale temporanea per estrarre i metadati.
        if ( str_starts_with( $path, 'mwstore://' ) ) {
            $backend = MediaWiki\MediaWikiServices::getInstance()->getFileBackendGroup()->backendFromPath( $path );
            if ( $backend ) {
                $tmpDir = wfTempDir();
                $localPath = $tmpDir . '/' . uniqid('odt_meta_tmp_', true);
                $status = $backend->getLocalCopy( [ 'src' => $path, 'dst' => $localPath ] );
                if ( $status && $status->isOK() ) {
                    $isTmp = true;
                } else {
                    $localPath = $path; // Fallback se il recupero fallisce
                }
            }
        }

        $width = 0;
        $height = 0;

        $zip = new ZipArchive();
        if ( $zip->open( $localPath ) === true ) {
            $data = $zip->getFromName('Thumbnails/thumbnail.png');
            $zip->close();
            
            if ( $data ) {
                $info = getimagesizefromstring( $data );
                if ( $info ) {
                    $width = $info[0];
                    $height = $info[1];
                }
            }
        }

        // Pulizia immediata del file temporaneo se lo abbiamo creato
        if ( $isTmp && file_exists( $localPath ) ) {
            unlink( $localPath );
        }

        // Se abbiamo trovato le dimensioni reali del PNG interno, le restituiamo
        if ( $width > 0 && $height > 0 ) {
            return [
                'width' => $width,
                'height' => $height,
                'metadata' => []
            ];
        }

        // Fallback in caso di file totalmente privi di anteprima
        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $fallbackImgPath = __DIR__ . '/../resources/default-' . $ext . '.png';
        if ( !file_exists( $fallbackImgPath ) ) {
            $fallbackImgPath = __DIR__ . '/../resources/default-odt.png';
        }
        
        if ( file_exists( $fallbackImgPath ) ) {
            $info = getimagesize( $fallbackImgPath );
            if ( $info ) {
                return [ 'width' => $info[0], 'height' => $info[1], 'metadata' => [] ];
            }
        }

        return [ 'width' => 250, 'height' => 250, 'metadata' => [] ];
    }

    public function normaliseParams( $image, &$params ) {
        if ( !isset( $params['width'] ) || $params['width'] <= 0 ) return false;
        $srcWidth = $image->getWidth();
        $srcHeight = $image->getHeight();
        if ( !isset( $params['height'] ) ) {
            $params['height'] = ( $srcWidth > 0 ) ? round( $params['width'] * ( $srcHeight / $srcWidth ) ) : $params['width'];
        }
        return true;
    }

    public function getParamMap() { return [ 'width' => 'width' ]; }
    public function validateParam( $name, $value ) { return ( $name === 'width' && $value > 0 ); }
    public function makeParamString( $params ) { return isset( $params['width'] ) ? "{$params['width']}px" : '200px'; }
    public function parseParamString( $str ) {
        if ( preg_match( '/^(\d+)px$/', $str, $m ) ) return [ 'width' => intval( $m[1] ) ];
        return [];
    }

    // 2. Il Motore di Conversione Grafica
    public function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
        
        $srcPath = $image->getLocalRefPath();
        if ( !$srcPath || !file_exists( $srcPath ) ) {
            return new MediaTransformError( 'thumbnail_error', $params['width'] ?? 180, $params['height'] ?? 180, 'File sorgente non trovato.' );
        }

        $zip = new ZipArchive();
        $thumbnailData = false;
        if ( $zip->open( $srcPath ) === true ) {
            $thumbnailData = $zip->getFromName('Thumbnails/thumbnail.png');
            $zip->close();
        }

        if ( !$thumbnailData ) {
            $ext = strtolower( pathinfo( $srcPath, PATHINFO_EXTENSION ) );
            $fallbackImgPath = __DIR__ . '/../resources/default-' . $ext . '.png';
            
            if ( !file_exists( $fallbackImgPath ) ) {
                $fallbackImgPath = __DIR__ . '/../resources/default-odt.png';
            }
            
            if ( file_exists( $fallbackImgPath ) ) {
                $thumbnailData = file_get_contents( $fallbackImgPath );
            } else {
                return new MediaTransformError( 'thumbnail_error', $params['width'] ?? 180, $params['height'] ?? 180, 'Anteprima non disponibile.' );
            }
        }

        $srcImg = imagecreatefromstring( $thumbnailData );
        $origWidth = imagesx( $srcImg );
        $origHeight = imagesy( $srcImg );
        
        $targetWidth = max( 1, (int)($params['width'] ?? $origWidth) );
        $targetHeight = max( 1, (int)($params['height'] ?? ( $origWidth > 0 ? round( $targetWidth * ( $origHeight / $origWidth ) ) : $targetWidth )) );

        $dstImg = imagecreatetruecolor( $targetWidth, $targetHeight );
        imagealphablending( $dstImg, false );
        imagesavealpha( $dstImg, true );
        $transparent = imagecolorallocatealpha( $dstImg, 255, 255, 255, 127 );
        imagefilledrectangle( $dstImg, 0, 0, $targetWidth, $targetHeight, $transparent );
        imagecopyresampled( $dstImg, $srcImg, 0, 0, 0, 0, $targetWidth, $targetHeight, $origWidth, $origHeight );
        imagedestroy( $srcImg );

        ob_start();
        imagepng( $dstImg );
        $finalPngData = ob_get_clean();
        imagedestroy( $dstImg );

        if ( strpos( $dstPath, 'mwstore://' ) === 0 ) {
            $backend = $image->getRepo()->getBackend();
            $backend->prepare( [ 'dir' => dirname( $dstPath ) ] );
            $status = $backend->quickCreate( [
                'content'   => $finalPngData,
                'dst'       => $dstPath,
                'overwrite' => true
            ] );
            
            if ( !$status->isOK() ) {
                return new MediaTransformError( 'thumbnail_error', $targetWidth, $targetHeight, 'Errore di scrittura in mwstore.' );
            }
        } else {
            $dir = dirname( $dstPath );
            if ( !is_dir( $dir ) ) {
                @mkdir( $dir, 0777, true );
            }
            $success = file_put_contents( $dstPath, $finalPngData );
            
            if ( $success === false ) {
                return new MediaTransformError( 'thumbnail_error', $targetWidth, $targetHeight, 'Errore di scrittura locale.' );
            }
        }

        return new ThumbnailImage( $image, $dstUrl, $targetWidth, $targetHeight, $dstPath );
    }
}