<?php
/**
 * This file is a part of the phpMussel\Web package.
 * Homepage: https://phpmussel.github.io/
 *
 * PHPMUSSEL COPYRIGHT 2013 AND BEYOND BY THE PHPMUSSEL TEAM.
 *
 * License: GNU/GPLv2
 * @see LICENSE.txt
 *
 * This file: Upload handler (last modified: 2023.04.02).
 */

namespace phpMussel\Web;

class Web
{
    /**
     * @var string A path for any custom front-end asset files.
     */
    public $CustomAssetsPath = '';

    /**
     * @var \phpMussel\Core\Loader The instantiated loader object.
     */
    private $Loader;

    /**
     * @var \phpMussel\Core\Scanner The instantiated scanner object.
     */
    private $Scanner;

    /**
     * @var string The path to the upload handler's asset files.
     */
    private $AssetsPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;

    /**
     * @var string The path to the upload handler's L10N files.
     */
    private $L10NPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'l10n' . DIRECTORY_SEPARATOR;

    /**
     * @var int The number of uploads caught by PHP.
     */
    private $Uploads = 0;

    /**
     * @var string An HTML string to attach to the generated output to indicate the output language.
     */
    private $Attache = '';

    /**
     * Construct the loader.
     *
     * @param \phpMussel\Core\Loader $Loader The instantiated loader object, passed by reference.
     * @param \phpMussel\Core\Scanner $Scanner The instantiated scanner object, passed by reference.
     * @return void
     */
    public function __construct(\phpMussel\Core\Loader &$Loader, \phpMussel\Core\Scanner &$Scanner)
    {
        /** Link the loader to this instance. */
        $this->Loader = &$Loader;

        /** Link the scanner to this instance. */
        $this->Scanner = &$Scanner;
        $this->Scanner->CalledFrom = 'Web';

        /** Load phpMussel upload handler configuration defaults and perform fallbacks. */
        if (
            is_readable($this->AssetsPath . 'config.yml') &&
            $Configuration = $this->Loader->readFile($this->AssetsPath . 'config.yml')
        ) {
            $Defaults = [];
            $this->Loader->YAML->process($Configuration, $Defaults);
            $this->Loader->fallback($Defaults);
            $this->Loader->ConfigurationDefaults = array_merge_recursive($this->Loader->ConfigurationDefaults, $Defaults);
        }

        /** Register log paths. */
        $this->Loader->InstanceCache['LogPaths'][] = $this->Loader->Configuration['web']['uploads_log'];

        /** Load phpMussel upload handler L10N data. */
        $this->Loader->loadL10N($this->L10NPath);

        /** Count uploads caught by PHP. */
        $this->Uploads = empty($_FILES) ? 0 : count($_FILES);

        /** Generate output language information attachment. */
        if ($this->Loader->L10NAccepted !== $this->Loader->ClientL10NAccepted) {
            $this->Attache = sprintf(
                ' lang="%s" dir="%s"',
                $this->Loader->ClientL10NAccepted,
                $this->Loader->ClientL10N->Directionality
            );
        }

        /**
         * Writes to the uploads log.
         *
         * @param string $Data What to write.
         * @return bool True on success; False on failure.
         */
        $this->Loader->Events->addHandler('writeToUploadsLog', function (string $Data): bool {
            /** Guard. */
            if (
                strlen($this->Loader->HashReference) === 0 ||
                $this->Loader->Configuration['web']['uploads_log'] === '' ||
                !($File = $this->Loader->buildPath($this->Loader->Configuration['web']['uploads_log']))
            ) {
                return false;
            }

            if (!file_exists($File)) {
                $Data = \phpMussel\Core\Loader::SAFETY . "\n\n" . $Data;
                $WriteMode = 'wb';
            } else {
                $Truncate = $this->Loader->readBytes($this->Loader->Configuration['core']['truncate']);
                $WriteMode = ($Truncate > 0 && filesize($File) >= $Truncate) ? 'wb' : 'ab';
            }
            $Stream = fopen($File, $WriteMode);
            fwrite($Stream, $Data);
            fclose($Stream);
            $this->Loader->logRotation($this->Loader->Configuration['web']['uploads_log']);
            return true;
        });
    }

    /**
     * Scan file uploads.
     */
    public function scan()
    {
        /** Fire event: "atStartOf_web_scan". */
        $this->Loader->Events->fireEvent('atStartOf_web_scan');

        /** Exit early if there isn't anything to scan, or if maintenance mode is enabled. */
        if (!$this->Uploads || $this->Loader->Configuration['core']['maintenance_mode']) {
            return;
        }

        /** Create empty handle array. */
        $Handle = [];

        /** Create an array for normalising the $_FILES data. */
        $FilesData = [];

        /** Create an array to designate the scan targets. */
        $FilesToScan = [];

        /** Normalise the structure of the files array. */
        foreach ($_FILES as $fileData) {
            /** Guard. */
            if (!isset($fileData['error'])) {
                continue;
            }

            if (is_array($fileData['name'])) {
                array_walk_recursive($fileData['name'], function ($item, $key) use (&$FilesData) {
                    $FilesData['name'][] = $item;
                });
                array_walk_recursive($fileData['type'], function ($item, $key) use (&$FilesData) {
                    $FilesData['type'][] = $item;
                });
                array_walk_recursive($fileData['tmp_name'], function ($item, $key) use (&$FilesData) {
                    $FilesData['tmp_name'][] = $item;
                });
                array_walk_recursive($fileData['error'], function ($item, $key) use (&$FilesData) {
                    $FilesData['error'][] = $item;
                });
                array_walk_recursive($fileData['size'], function ($item, $key) use (&$FilesData) {
                    $FilesData['size'][] = $item;
                });
            } else {
                $FilesData['name'][] = $fileData['name'];
                $FilesData['type'][] = $fileData['type'];
                $FilesData['tmp_name'][] = $fileData['tmp_name'];
                $FilesData['error'][] = $fileData['error'];
                $FilesData['size'][] = $fileData['size'];
            }
        }

        $FilesCount = count($FilesData['error']);

        /** Iterate through normalised array and scan as necessary. */
        for ($Iterator = 0; $Iterator < $FilesCount; $Iterator++) {
            if (!isset($FilesData['name'][$Iterator])) {
                $FilesData['name'][$Iterator] = '';
            }
            if (!isset($FilesData['type'][$Iterator])) {
                $FilesData['type'][$Iterator] = '';
            }
            if (!isset($FilesData['tmp_name'][$Iterator])) {
                $FilesData['tmp_name'][$Iterator] = '';
            }
            if (!isset($FilesData['error'][$Iterator])) {
                $FilesData['error'][$Iterator] = 0;
            }
            if (!isset($FilesData['size'][$Iterator])) {
                $FilesData['size'][$Iterator] = 0;
            }

            unset($ThisError);
            $ThisError = &$FilesData['error'][$Iterator];

            /** Handle upload errors. */
            if ($ThisError > 0) {
                if ($this->Loader->Configuration['compatibility']['ignore_upload_errors'] || $ThisError > 8 || $ThisError === 5) {
                    continue;
                }
                $this->Loader->atHit('', -1, '', sprintf(
                    $this->Loader->L10N->getString('grammar_exclamation_mark'),
                    $this->Loader->L10N->getString('upload_error_' . (($ThisError === 3 || $ThisError === 4) ? '34' : $ThisError))
                ), -5, -1);
                if (
                    ($ThisError === 1 || $ThisError === 2) &&
                    $this->Loader->Configuration['core']['delete_on_sight'] &&
                    is_uploaded_file($FilesData['tmp_name'][$Iterator]) &&
                    is_readable($FilesData['tmp_name'][$Iterator])
                ) {
                    unlink($FilesData['tmp_name'][$Iterator]);
                }
                continue;
            }

            /** Protection against upload spoofing (1/2). */
            if (
                !$FilesData['name'][$Iterator] ||
                !$FilesData['tmp_name'][$Iterator]
            ) {
                $this->Loader->atHit('', -1, '', $this->Loader->L10N->getString('scan_unauthorised_upload_or_misconfig'), -5, -1);
                continue;
            }

            /** Protection against upload spoofing (2/2). */
            if (!is_uploaded_file($FilesData['tmp_name'][$Iterator])) {
                $this->Loader->atHit(
                    '',
                    $FilesData['size'][$Iterator],
                    $FilesData['name'][$Iterator],
                    $this->Loader->L10N->getString('scan_unauthorised_upload'),
                    -5,
                    -1
                );
                continue;
            }

            /** Process this block if the number of files being uploaded exceeds "max_uploads". */
            if (
                $this->Loader->Configuration['web']['max_uploads'] >= 1 &&
                $this->Uploads > $this->Loader->Configuration['web']['max_uploads']
            ) {
                $this->Loader->atHit('', $FilesData['size'][$Iterator], $FilesData['name'][$Iterator], sprintf(
                    $this->Loader->L10N->getString('grammar_exclamation_mark'),
                    sprintf(
                        $this->Loader->L10N->getString('grammar_brackets'),
                        $this->Loader->L10N->getString('upload_limit_exceeded'),
                        $FilesData['name'][$Iterator]
                    )
                ), -5, -1);
                if (
                    $this->Loader->Configuration['core']['delete_on_sight'] &&
                    is_uploaded_file($FilesData['tmp_name'][$Iterator]) &&
                    is_readable($FilesData['tmp_name'][$Iterator])
                ) {
                    unlink($FilesData['tmp_name'][$Iterator]);
                }
                continue;
            }

            /** Designate as scan target. */
            $FilesToScan[$FilesData['name'][$Iterator]] = $FilesData['tmp_name'][$Iterator];
        }

        /** Check these first, because they'll reset otherwise, then execute the scan. */
        if (!count($this->Loader->ScanResultsText) && count($FilesToScan)) {
            $this->Scanner->scan($FilesToScan, 4);
        }

        /** Exit here if there aren't any file upload detections. */
        if (empty($this->Loader->InstanceCache['DetectionsCount'])) {
            return;
        }

        /** Build detections. */
        $Detections = implode($this->Loader->L10N->getString('grammar_spacer'), $this->Loader->ScanResultsText);

        /** Merging parsable variables for the template data. */
        $TemplateData = [
            'magnification' => $this->Loader->Configuration['web']['magnification'],
            'CustomHeader' => $this->Loader->Configuration['web']['custom_header'],
            'CustomFooter' => $this->Loader->Configuration['web']['custom_footer'],
            'Attache' => $this->Attache,
            'GeneratedBy' => sprintf(
                $this->Loader->ClientL10N->getString('generated_by'),
                '<div id="phpmusselversion" dir="ltr">' . $this->Loader->ScriptIdent . '</div>'
            ),
            'detected' => $Detections,
            'favicon' => base64_encode($this->Loader->getFavicon()),
            'xmlLang' => $this->Loader->L10NAccepted,
            'Text Direction' => $this->Loader->L10N->Directionality,
            'FE_Align' => $this->Loader->L10N->Directionality === 'rtl' ? 'right' : 'left',
            'FE_Align_Reverse' => $this->Loader->L10N->Directionality === 'rtl' ? 'left' : 'right'
        ];

        /** Pull relevant client-specified L10N data. */
        if (!empty($this->Attache)) {
            foreach (['denied', 'denied_reason'] as $Pull) {
                if (isset($this->ClientL10N->Data[$Pull])) {
                    $TemplateData[$Pull] = $this->ClientL10N->Data[$Pull];
                }
            }
            unset($Pull);
        }

        /** Determine which template file to use. */
        if ($this->CustomAssetsPath) {
            if (is_readable($this->CustomAssetsPath . 'template_' . $this->Loader->Configuration['web']['theme'] . '.html')) {
                $TemplateFile = $this->CustomAssetsPath . 'template_' . $this->Loader->Configuration['web']['theme'] . '.html';
            } elseif (is_readable($this->CustomAssetsPath . 'template_default.html')) {
                $TemplateFile = $this->CustomAssetsPath . 'template_default.html';
            }
        } else {
            if (is_readable($this->AssetsPath . 'template_' . $this->Loader->Configuration['web']['theme'] . '.html')) {
                $TemplateFile = $this->AssetsPath . 'template_' . $this->Loader->Configuration['web']['theme'] . '.html';
            } elseif (is_readable($this->AssetsPath . 'template_default.html')) {
                $TemplateFile = $this->AssetsPath . 'template_default.html';
            }
        }
        if (empty($TemplateFile)) {
            $TemplateFile = '';
        }

        /** Log "uploads_log" data. */
        if (strlen($this->Loader->HashReference) !== 0) {
            $Handle['Data'] = sprintf(
                "%s: %s\n%s: %s\n== %s ==\n%s\n== %s ==\n%s",
                $this->Loader->L10N->getString('field_date'),
                $this->Loader->timeFormat($this->Loader->Time, $this->Loader->Configuration['core']['time_format']),
                $this->Loader->L10N->getString('field_ip_address'),
                $this->Loader->Configuration['legal']['pseudonymise_ip_addresses'] ? $this->Loader->pseudonymiseIP($this->Loader->IPAddr) : $this->Loader->IPAddr,
                $this->Loader->L10N->getString('field_header_scan_results_why_flagged'),
                $Detections,
                $this->Loader->L10N->getString('field_header_hash_reconstruction'),
                $this->Loader->HashReference
            );
            if ($this->Loader->PEData) {
                $Handle['Data'] .= sprintf(
                    "== %s ==\n%s",
                    $this->Loader->L10N->getString('field_header_pe_reconstruction'),
                    $this->Loader->PEData
                );
            }
            $Handle['Data'] .= "\n";
            $this->Loader->Events->fireEvent('writeToUploadsLog', $Handle['Data']);
            $Handle = [];
        }

        /** Fallback to use if the HTML template file is missing. */
        if (!$TemplateFile) {
            header('Content-Type: text/plain');
            die('[phpMussel] ' . $this->Loader->ClientL10N->getString('denied') . ' ' . $TemplateData['detected']);
        }

        /** Determine whether to send a 415 UNSUPPORTED MEDIA TYPE status code. */
        if (
            $this->Loader->Configuration['web']['unsupported_media_type_header'] &&
            !empty($this->Loader->InstanceCache['blacklist_triggered'])
        ) {
            header('HTTP/1.0 415 Unsupported Media Type');
            header('HTTP/1.1 415 Unsupported Media Type');
            header('Status: 415 Unsupported Media Type');
        }

        /** Determine whether to send a 403 FORBIDDEN status code. */
        elseif ($this->Loader->Configuration['web']['forbid_on_block']) {
            header('HTTP/1.0 403 Forbidden');
            header('HTTP/1.1 403 Forbidden');
            header('Status: 403 Forbidden');
        }

        /** Include privacy policy. */
        $TemplateData['pp'] = empty(
            $this->Loader->Configuration['legal']['privacy_policy']
        ) ? '' : '<br /><a href="' . $this->Loader->Configuration['legal']['privacy_policy'] . '">' . $this->ClientL10N->L10N->getString('PrivacyPolicy') . '</a>';

        /** Generate HTML output. */
        $Output = $this->Loader->parse(
            $this->Loader->ClientL10N->Data,
            $this->Loader->parse($TemplateData, $this->Loader->readFile($TemplateFile))
        );

        /** Send email notifications about blocked uploads (if enabled). */
        if (strlen($this->Loader->InstanceCache['enable_notifications'])) {
            /** Generate email body. */
            $EmailBody = sprintf(
                $this->Loader->L10N->getString('notifications_message'),
                preg_replace(['~^([\da-z]+\:\d+\:)~i', '~\n~'], ['', "<br />\n"], strip_tags($this->Loader->HashReference)),
                $TemplateData['detected'],
                $this->Loader->timeFormat($this->Loader->Time, $this->Loader->Configuration['core']['time_format'])
            );

            /** Generate email event data. */
            $EventData = [
                [],
                $this->Loader->L10N->getString('notifications_subject'),
                $EmailBody,
                strip_tags($EmailBody),
                ''
            ];

            /** Process recipients. */
            foreach (explode(',', $this->Loader->InstanceCache['enable_notifications']) as $Recipient) {
                if ($Recipient === '') {
                    continue;
                }
                if (preg_match('~^[^<>]+ <[^<>]+>$~', $Recipient)) {
                    $Name = preg_replace('~^([^<>]+) <[^<>]+>$~', '\1', $Recipient);
                    $Address = preg_replace('~^[^<>]+ <([^<>]+)>$~', '\1', $Recipient);
                } else {
                    $Name = $Recipient;
                    $Address = $Recipient;
                }
                $EventData[0][] = ['Name' => $Name, 'Address' => $Address];
            }

            /** Send the notification. */
            $this->Loader->Events->fireEvent('sendMail', '', ...$EventData);
        }

        /** Before output event. */
        $this->Loader->Events->fireEvent('beforeOutput', '', $Output);

        /** Send HTML output and the kill the script. */
        die($Output);
    }

    /**
     * A method provided for running the names of uploaded files through the
     * demojibakefier for the optional use of the implementation (warning: this
     * will modify the "name" value of the entries in $_FILES).
     *
     * @param string $Encoding The implementation may optionally specify the
     *      preferred encoding for the demojibakefier to normalise names to. It
     *      is generally recommended to leave it at its default, however.
     */
    public function demojibakefier(string $Encoding = 'UTF-8')
    {
        /** Instantiate the demojibakefier class. */
        $Demojibakefier = new \Maikuolan\Common\Demojibakefier($Encoding);

        /** Exit early if there isn't anything to run through. */
        if (!$this->Uploads) {
            return;
        }

        /** Iterate through the $_FILES array. */
        foreach ($_FILES as &$FileData) {
            /** Guard. */
            if (!isset($FileData['name'])) {
                continue;
            }

            /** Run the names through the demojibakefier. */
            if (is_array($FileData['name'])) {
                foreach ($FileData['name'] as &$FileName) {
                    if (is_string($FileName)) {
                        $FileName = $Demojibakefier->guard($FileName);
                    }
                }
            } elseif (is_string($FileData['name'])) {
                $FileData['name'] = $Demojibakefier->guard($FileData['name']);
            }
        }
    }
}
