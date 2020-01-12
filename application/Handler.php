<?php

/**
 * =========================================================================
 * LICENSE: This file is subject to the terms and conditions defined in    *
 * file 'LICENSE', which is part of the AAM Protected Media Files package  *
 * =========================================================================
 */

namespace AAM\AddOn\ProtectedMediaFiles;

/**
 * File access handler
 *
 * @since 1.1.3 Fixed bug with not properly managed access when website is in
 *              subfolder
 * @since 1.1.2 Fixed bug with incorrectly returned image size
 * @since 1.1.1 Fixed couple bugs related to redirect and file finding
 * @since 1.1.0 Added deeper integration with AAM for access denied redirect
 * @since 1.0.0 Initial implementation of the class
 *
 * @package AAM\AddOn\ProtectedMediaFiles
 * @author Vasyl Martyniuk <vasyl@vasyltech.com>
 * @version 1.1.3
 */
class Handler
{
    /**
     * Instance of itself
     *
     * @var AAM\AddOn\ProtectedMediaFiles\Handler
     *
     * @access private
     *
     * @version 1.0.0
     */
    private static $_instance = null;

    /**
     * Requested file
     *
     * @var string
     *
     * @access protected
     * @version 1.0.0
     */
    protected $request;

    /**
     * Initialize the access to files
     *
     * @return void
     *
     * @access protected
     *
     * @version 1.0.0
     */
    protected function __construct()
    {
        $media = $this->getFromQuery('aam-media');

        if (is_numeric($media)) { // Redirecting with aam-media=1 query param
            $request = $this->getFromServer('REQUEST_URI');
        } else { // Otherwise, this is most likely Nginx redirect rule
            $request = $media;
        }

        // Stripping any query params
        $this->request = ltrim(preg_replace('/(\?.*|#)$/', '', $request), '/');
    }

    /**
     * Authorize direct access to file
     *
     * @return void
     *
     * @since 1.1.3 Changed the way full path is computed
     * @since 1.1.2 Fixed bug with incorrectly returned image size
     * @since 1.1.1 Fixed issue with incorrectly redirected user when access is
     *              denied
     * @since 1.1.0 Added the ability to invoke AAM Access Denied Redirect service
     * @since 1.0.0 Initial implementation of the method
     *
     * @access public
     * @version 1.1.3
     */
    public function authorize()
    {
        // First, let's check if URI is restricted
        \AAM_Service_Uri::getInstance()->authorizeUri();

        $media = $this->findMedia();

        if ($media === null) { // File is not part of the Media library
            $this->_outputFile($this->_getFileFullpath());
        } else {
            if ($media->is('restricted')) {
                if (\AAM::api()->getConfig(
                    'addon.protected-media-files.settings.deniedRedirect', false
                )) {
                    wp_die(
                        'Access Denied',
                        'aam_access_denied',
                        array('exit' => true)
                    );
                } else {
                    http_response_code(401); exit;
                }
            } else {
                $this->_outputFile($this->_getFileFullpath());
            }
        }
    }

    /**
     * Find file based on the URI
     *
     * @return AAM_Core_Object_Post|null
     *
     * @since 1.1.3 Changed the way full path is computed
     * @since 1.1.1 Covered the edge case when file name is somename-nnnxnnn
     * @since 1.0.0 Initial implementation of the method
     *
     * @access protected
     * @global WPDB $wpdb
     * @version 1.1.3
     */
    protected function findMedia()
    {
        global $wpdb;

        $file_path = $this->_getFileFullpath();

        // 1. Replace the cropped extension for images
        $s = preg_replace('/(-[\d]+x[\d]+)(\.[\w]+)$/', '$2', $file_path);

        // 2. Replace the path to the media
        $basedir = wp_upload_dir();

        // Covering the scenario when filename is actually something like
        // 2019/11/banner-1544x500.png
        $relpath_base = str_replace($basedir['basedir'], '', $s);
        $relpath_full = str_replace($basedir['basedir'], '', $file_path);

        $pm_query  = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ";
        $pm_query .= "AND (meta_value = %s OR meta_value = %s)";

        $id = $wpdb->get_var(
            $wpdb->prepare(
                $pm_query,
                array(
                    '_wp_attached_file',
                    ltrim($relpath_base, '/'),
                    ltrim($relpath_full, '/')
                )
            )
        );

        if (empty($id)) { // Try to find the image by GUID
            $id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE guid LIKE %s",
                    array('%' . $s)
                )
            );
        }

        return ($id ? \AAM::getUser()->getObject(
            \AAM_Core_Object_Post::OBJECT_TYPE, $id) : null
        );
    }

    /**
     * Compute correct physical location to the file
     *
     * @return string
     *
     * @access private
     * @version 1.1.3
     */
    private function _getFileFullpath()
    {
        $root = $this->getFromServer('DOCUMENT_ROOT');
        $base = (empty($root) ? ABSPATH : $root . '/');

        return $base . $this->request;
    }

    /**
     * Output file if valid
     *
     * @param string      $filename
     * @param string|null $mime
     *
     * @return void
     *
     * @access private
     * @version 1.0.0
     */
    private function _outputFile($filename, $mime = null)
    {
        if ($this->isAllowed($filename)) {
            if (empty($mime)) {
                if (function_exists('mime_content_type')) {
                    $mime = mime_content_type($filename);
                } else {
                    $mime = 'application/octet-stream';
                }
            }

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($filename));

            // Calculate etag
            $last_modified = gmdate('D, d M Y H:i:s', filemtime($filename));
            $etag = '"' . md5( $last_modified ) . '"';

            header("Last-Modified: $last_modified GMT");
            header("ETag: {$etag}");
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 100000000) . ' GMT');

            // Finally read the file
            readfile($filename);
        } else {
            http_response_code(403);
        }

        exit;
    }

    /**
     * Making sure that file can be released
     *
     * @param string $filename
     *
     * @return boolean
     *
     * @access private
     * @version 1.0.0
     */
    private function isAllowed($filename)
    {
        // Check if file extension is valid
        $type_check = wp_check_filetype(basename($filename));
        $location   = wp_get_upload_dir();

        // Get upload directory attributes
        if (!empty($location['basedir'])) {
            $upload_dir = $location['basedir'];
        } else {
            $upload_dir = WP_CONTENT_DIR . '/uploads';
        }

        // Check if file is withing uploads directory
        $isWithinUploads = (strpos($filename, realpath($upload_dir)) !== false);

        // Security checks. Making sure that file is located in the Uploads directory
        // and the file extension is valid to current WP instance
        return !empty($type_check['ext']) && $isWithinUploads;
    }

    /**
     * Get data from the GET/Query
     *
     * @param string $param
     * @param int    $filter
     * @param int    $options
     *
     * @return mixed
     *
     * @access protected
     * @version 1.0.0
     */
    protected function getFromQuery($param, $filter = FILTER_DEFAULT, $options = null)
    {
        $get = filter_input(INPUT_GET, $param, $filter, $options);

        if (is_null($get)) {
            $get = filter_var($this->readFromArray($_GET, $param), $filter, $options);
        }

        return $get;
    }

    /**
     * Get data from the super-global $_SERVER
     *
     * @param string $param
     * @param int    $filter
     * @param int    $options
     *
     * @return mixed
     *
     * @access protected
     * @version 1.0.0
     */
    protected function getFromServer($param, $filter = FILTER_DEFAULT, $options = null)
    {
        $var = filter_input(INPUT_SERVER, $param, $filter, $options);

        // Cover the unexpected server issues (e.g. FastCGI may cause unexpected null)
        if (empty($var)) {
            $var = filter_var(
                $this->readFromArray($_SERVER, $param), $filter, $options
            );
        }

        return $var;
    }

    /**
     * Check array for specified parameter and return the it's value or
     * default one
     *
     * @param array  $array   Global array _GET, _POST etc
     * @param string $param   Array Parameter
     * @param mixed  $default Default value
     *
     * @return mixed
     *
     * @access protected
     * @version 1.0.0
     */
    protected function readFromArray($array, $param, $default = null)
    {
        $value = $default;

        if (is_null($param)) {
            $value = $array;
        } else {
            $chunks = explode('.', $param);
            $value = $array;
            foreach ($chunks as $chunk) {
                if (isset($value[$chunk])) {
                    $value = $value[$chunk];
                } else {
                    $value = $default;
                    break;
                }
            }
        }

        return $value;
    }

    /**
     * Bootstrap the handler
     *
     * @return AAM\AddOn\ProtectedMediaFiles\Handler
     *
     * @access public
     * @version 1.0.0
     */
    public static function bootstrap()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self;
        }

        return self::$_instance;
    }

}