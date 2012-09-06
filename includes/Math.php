<?php
/**
 * Contain everything related to <math> </math> parsing
 */

class MathRenderer {
        
        //shell programs:
        var $LATEX_PATH = "/usr/bin/latex";
        var $DVIPS_PATH = "/usr/bin/dvips";
        var $CONVERT_PATH = "/usr/bin/convert";
        
        //image url stuff
        var $URL_PATH = "http://www.mymcat.com/testing/cache";

        var $tex = '';
        var $inputhash = '';
        var $hash = '';
        var $html = '';

        //right now we have NO params, but it might be worth keeping...
        function __construct( $tex, $params=array() ) {
                $this->tex = $tex;
                $this->params = $params;
        }

        function render() {
                global $wgTmpDirectory;
                $fname = 'MathRenderer::render';

                if( !$this->_recall() ) {
                        # Ensure that the temp and output directories are available before continuing...
                        if( !file_exists( $wgTmpDirectory ) ) {
                                if( !@mkdir( $wgTmpDirectory ) ) {
                                        return $this->_error( "wgTmpDirectory: $wgTmpDirectory does not exist! " );
                                }
                        } elseif( !is_dir( $wgTmpDirectory ) || !is_writable( $wgTmpDirectory ) ) {
                                return $this->_error( "wgTmpDirectory: $wgTmpDirectory is not accessible!" );
                        }

                        if( function_exists( 'is_executable' ) && !is_executable( $this->LATEX_PATH ) ) {
                                return $this->_error( "latex not found..." );
                        }
                        if( function_exists( 'is_executable' ) && !is_executable( $this->DVIPS_PATH ) ) {                                return $this->_error( "dvips not found" );      
                        }       
                        if( function_exists( 'is_executable' ) && !is_executable( $this->CONVERT_PATH ) ) {                                return $this->_error( "convert (imagemagick) not found" );   
                        }


                        //wrap the math text with the generic latex requirements
                        //in the future, this wrapper should be modifyable by the parameters
                        $thunk = $this->_wrap($this->tex);
                        
                        //begin working...
                        $hash = md5($this->tex);
                        $this->hash = $hash;
                        wfDebug( "Math: hash is: $this->hash\n" );

                        //get to the tmp dir:
                        $current_dir = getcwd();
                        chdir( $wgTmpDirectory );
                        
                        // create temporary LaTeX file
                        $fp = fopen( "$hash.tex", "w+");
                        fputs($fp, $thunk);
                        fclose($fp);

                        //run latex:
                        $command = $this->LATEX_PATH . " --interaction=nonstopmode " . $hash . ".tex";
                        exec($command);
                        wfDebug( "Math: latex command, $command\n" );

                        //run dvips:
                        $command = $this->DVIPS_PATH . " -E $hash" . ".dvi -o " .  "$hash.ps";
                        exec($command);
                        wfDebug( "Math: dvips command, $command\n" );


                        //run ps through imageMagick:
                        $command = $this->CONVERT_PATH . " -density 120 $hash.ps $hash.png";
                        exec($command);
                        wfDebug( "Math: convert command, $command\n" );

        //              copy("$hash.png", $this->CACHE_DIR . "/$hash.png");
                        chdir($current_dir);

                        if (!preg_match("/^[a-f0-9]{32}$/", $this->hash)) {
                                return $this->_error( "could not match the hash anywhere" );
                        }

                        if( !file_exists( "$wgTmpDirectory/{$this->hash}.png" ) ) {
                                return $this->_error( 'math_image_error' . " $wgTmpDirectory/{$this->hash}.png  , $dirandhash , " . getcwd() );
                        }

                        $hashpath = $this->_getHashPath();
                        if( !file_exists( $hashpath ) ) {
                                if( !@wfMkdirParents( $hashpath, 0755 ) ) {
                                        return $this->_error( "hashpath error type one: $hashpath" );
                                }
                        } elseif( !is_dir( $hashpath ) || !is_writable( $hashpath ) ) {
                                return $this->_error( 'hashpath error type two' );
                        }

                        if( !rename( "$wgTmpDirectory/{$this->hash}.png", "$hashpath/{$this->hash}.png" ) ) {
                                return $this->_error( "hashpath rename failed" );
                        }

                        # Now save it back to the DB:
                        if ( !wfReadOnly() ) {
                                $outmd5_sql = pack('H32', $this->hash);

                                $md5_sql = pack('H32', $this->md5); # Binary packed, not hex

                                $dbw = wfGetDB( DB_MASTER );
                                $dbw->replace( 'math', array( 'math_inputhash' ),
                                  array(
                                        'math_inputhash' => $dbw->encodeBlob($md5_sql),
                                        'math_outputhash' => $dbw->encodeBlob($outmd5_sql),
                                        'math_html_conservativeness' => "",
                                        'math_html' => $this->html,
                                        'math_mathml' => "",
                                  ), $fname, array( 'IGNORE' )
                                );
                        }
                        
                        $this->_cleanup( $hash );
                }
                
                return $this->_doRender();
        }

        function _error( $msg, $append = '' ) {
                $mf   = htmlspecialchars( wfMsg( 'math_failure' ) );
                $errmsg = htmlspecialchars( $msg );
                $source = htmlspecialchars( str_replace( "\n", ' ', $this->tex ) );
                return "<strong class='error'>$mf ($errmsg$append): $source</strong>\n";
        }

        function _wrap($thunk) {
                return <<<EOS
                \documentclass[10pt]{article}

                % add additional packages here
                \usepackage{amsmath}
                \usepackage{amsfonts}
                \usepackage{amssymb}
                \usepackage{pst-plot}
                \usepackage{color}

                \pagestyle{empty}
                \begin{document}
                \begin{equation*}
                \large
                $thunk
                \end{equation*}
                \end{document}
EOS;
        }


        function _cleanup($hash) {

                $current_dir = getcwd();
                chdir( $wgTmpDirectory );

                unlink($this->TMP_DIR . "/$hash.tex");
                unlink($this->TMP_DIR . "/$hash.aux");
                unlink($this->TMP_DIR . "/$hash.log");
                unlink($this->TMP_DIR . "/$hash.dvi");
                unlink($this->TMP_DIR . "/$hash.ps");
                unlink($this->TMP_DIR . "/$hash.png");

                chdir($current_dir);
        }

        function _recall() {
                global $wgMathDirectory;
                $fname = 'MathRenderer::_recall';

                $this->md5 = md5( $this->tex );
                $dbr = wfGetDB( DB_SLAVE );
                $rpage = $dbr->selectRow( 'math',
                        array( 'math_outputhash','math_html_conservativeness','math_html','math_mathml' ),
                        array( 'math_inputhash' => $dbr->encodeBlob(pack("H32", $this->md5))), # Binary packed, not hex
                        $fname
                );

                if( $rpage !== false ) {
                        # Tailing 0x20s can get dropped by the database, add it back on if necessary:
                        $xhash = unpack( 'H32md5', $dbr->decodeBlob($rpage->math_outputhash) . "                " );
                        $this->hash = $xhash ['md5'];

                        $this->conservativeness = $rpage->math_html_conservativeness;
                        $this->html = $rpage->math_html;
                        $this->mathml = $rpage->math_mathml;

                        if( file_exists( $this->_getHashPath() . "/{$this->hash}.png" ) ) {
                                return true;
                        }

                        if( file_exists( $wgMathDirectory . "/{$this->hash}.png" ) ) {
                                $hashpath = $this->_getHashPath();

                                if( !file_exists( $hashpath ) ) {
                                        if( !@wfMkdirParents( $hashpath, 0755 ) ) {
                                                return false;
                                        }
                                } elseif( !is_dir( $hashpath ) || !is_writable( $hashpath ) ) {
                                        return false;
                                }
                                if ( function_exists( "link" ) ) {
                                        return link ( $wgMathDirectory . "/{$this->hash}.png",
                                                        $hashpath . "/{$this->hash}.png" );
                                } else {
                                        return rename ( $wgMathDirectory . "/{$this->hash}.png",
                                                        $hashpath . "/{$this->hash}.png" );
                                }
                        }

                }

                # Missing from the database and/or the render cache
                return false;
        }

        /**
         * Select among PNG, HTML, or MathML output depending on
         * THIS ONLY does PNG now...
         */
        function _doRender() {
                return $this->_linkToMathImage();
        }
        
        function _attribs( $tag, $defaults=array(), $overrides=array() ) {
                $attribs = Sanitizer::validateTagAttributes( $this->params, $tag );
                $attribs = Sanitizer::mergeAttributes( $defaults, $attribs );
                $attribs = Sanitizer::mergeAttributes( $attribs, $overrides );
                return $attribs;
        }

        function _linkToMathImage() {
                global $wgMathPath;
                $url = "$wgMathPath/" . substr($this->hash, 0, 1)
                                        .'/'. substr($this->hash, 1, 1) .'/'. substr($this->hash, 2, 1)
                                        . "/{$this->hash}.png";

                return Xml::element( 'img',
                        $this->_attribs(
                                'img',
                                array(
                                        'class' => 'tex',
                                        'alt' => $this->tex ),
                                array(
                                        'src' => $url ) ) );
        }

        function _getHashPath() {
                global $wgMathDirectory;
                $path = $wgMathDirectory .'/'. substr($this->hash, 0, 1)
                                        .'/'. substr($this->hash, 1, 1)
                                        .'/'. substr($this->hash, 2, 1);
                wfDebug( "TeX: getHashPath, hash is: $this->hash, path is: $path\n" );
                return $path;
        }

        public static function renderMath( $tex, $params=array() ) {
                global $wgUser;
                $math = new MathRenderer( $tex, $params );
               $math->setOutputMode( $wgUser->getOption('math'));
                return $math->render();
            
        }
}

