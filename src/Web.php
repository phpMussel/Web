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
 * This file: Upload handler (last modified: 2020.07.11).
 */

namespace phpMussel\Web;

class Web
{
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
            if (isset($Defaults)) {
                $this->Loader->fallback($Defaults);
                $this->Loader->ConfigurationDefaults = array_merge_recursive($this->Loader->ConfigurationDefaults, $Defaults);
            }
        }

        /** Register log paths. */
        $this->Loader->InstanceCache['LogPaths'][] = $this->Loader->Configuration['web']['uploads_log'];

        /** Load phpMussel upload handler L10N data. */
        $this->Loader->loadL10N($this->L10NPath);

        /** Count uploads caught by PHP. */
        $this->Uploads = empty($_FILES) ? 0 : count($_FILES);

        /** Generate output language information attachment. */
        if ($this->Loader->Configuration['core']['lang'] !== $this->Loader->ClientL10NAccepted) {
            $this->Attache = sprintf(
                ' lang="%s" dir="%s"',
                $this->Loader->ClientL10NAccepted,
                $this->Loader->L10N->Data['Text Direction'] ?? 'ltr'
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
                !($File = $this->Loader->buildPath($this->Loader->Configuration['web']['uploads_log']))
            ) {
                return false;
            }

            if (!file_exists($File)) {
                $Data = \phpMussel\Core\Loader::SAFETY . "\n\n" . $Data;
                $WriteMode = 'wb';
            } else {
                $WriteMode = (
                    $this->Loader->Configuration['core']['truncate'] > 0 &&
                    filesize($File) >= $this->Loader->readBytes($this->Loader->Configuration['core']['truncate'])
                ) ? 'wb' : 'ab';
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

        /** Iterate through $_FILES array and scan as necessary. */
        foreach ($_FILES as $FileKey => $FileData) {
            /** Guard. */
            if (!isset($FileData['error'])) {
                continue;
            }

            /** Normalise the structure of the uploads array. */
            if (!is_array($FileData['error'])) {
                $FilesData['FileSet'] = [
                    'name' => [$FileData['name']],
                    'type' => [$FileData['type']],
                    'tmp_name' => [$FileData['tmp_name']],
                    'error' => [$FileData['error']],
                    'size' => [$FileData['size']]
                ];
            } else {
                $FilesData['FileSet'] = $FileData;
            }
            $FilesCount = count($FilesData['FileSet']['error']);

            /** Iterate through fileset. */
            for ($Iterator = 0; $Iterator < $FilesCount; $Iterator++) {
                if (!isset($FilesData['FileSet']['name'][$Iterator])) {
                    $FilesData['FileSet']['name'][$Iterator] = '';
                }
                if (!isset($FilesData['FileSet']['type'][$Iterator])) {
                    $FilesData['FileSet']['type'][$Iterator] = '';
                }
                if (!isset($FilesData['FileSet']['tmp_name'][$Iterator])) {
                    $FilesData['FileSet']['tmp_name'][$Iterator] = '';
                }
                if (!isset($FilesData['FileSet']['error'][$Iterator])) {
                    $FilesData['FileSet']['error'][$Iterator] = 0;
                }
                if (!isset($FilesData['FileSet']['size'][$Iterator])) {
                    $FilesData['FileSet']['size'][$Iterator] = 0;
                }

                unset($ThisError);
                $ThisError = &$FilesData['FileSet']['error'][$Iterator];

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
                        is_uploaded_file($FilesData['FileSet']['tmp_name'][$Iterator]) &&
                        is_readable($FilesData['FileSet']['tmp_name'][$Iterator])
                    ) {
                        unlink($FilesData['FileSet']['tmp_name'][$Iterator]);
                    }
                    continue;
                }

                /** Protection against upload spoofing (1/2). */
                if (
                    !$FilesData['FileSet']['name'][$Iterator] ||
                    !$FilesData['FileSet']['tmp_name'][$Iterator]
                ) {
                    $this->Loader->atHit('', -1, '', sprintf(
                        $this->Loader->L10N->getString('grammar_exclamation_mark'),
                        $this->Loader->L10N->getString('scan_unauthorised_upload_or_misconfig')
                    ), -5, -1);
                    continue;
                }

                /** Protection against upload spoofing (2/2). */
                if (!is_uploaded_file($FilesData['FileSet']['tmp_name'][$Iterator])) {
                    $this->Loader->atHit('', $FilesData['FileSet']['size'][$Iterator], $FilesData['FileSet']['name'][$Iterator], sprintf(
                        $this->Loader->L10N->getString('grammar_exclamation_mark'),
                        sprintf(
                            $this->Loader->L10N->getString('grammar_brackets'),
                            $this->Loader->L10N->getString('scan_unauthorised_upload'),
                            $FilesData['FileSet']['name'][$Iterator]
                        )
                    ), -5, -1);
                    continue;
                }

                /** Process this block if the number of files being uploaded exceeds "max_uploads". */
                if (
                    $this->Loader->Configuration['web']['max_uploads'] >= 1 &&
                    $this->Uploads > $this->Loader->Configuration['web']['max_uploads']
                ) {
                    $this->Loader->atHit('', $FilesData['FileSet']['size'][$Iterator], $FilesData['FileSet']['name'][$Iterator], sprintf(
                        $this->Loader->L10N->getString('grammar_exclamation_mark'),
                        sprintf(
                            $this->Loader->L10N->getString('grammar_brackets'),
                            $this->Loader->L10N->getString('upload_limit_exceeded'),
                            $FilesData['FileSet']['name'][$Iterator]
                        )
                    ), -5, -1);
                    if (
                        $this->Loader->Configuration['core']['delete_on_sight'] &&
                        is_uploaded_file($FilesData['FileSet']['tmp_name'][$Iterator]) &&
                        is_readable($FilesData['FileSet']['tmp_name'][$Iterator])
                    ) {
                        unlink($FilesData['FileSet']['tmp_name'][$Iterator]);
                    }
                    continue;
                }

                /** Designate as scan target. */
                $FilesToScan[$FilesData['FileSet']['name'][$Iterator]] = $FilesData['FileSet']['tmp_name'][$Iterator];
            }
        }

        /** Check these first, because they'll reset otherwise, then execute the scan. */
        if (!count($this->Loader->ScanResultsText) && count($FilesToScan)) {
            $this->Scanner->scan($FilesToScan, 4);
        }

        /** Begin processing file upload detections. */
        if (count($this->Loader->ScanResultsText)) {
            /** Build detections. */
            $Detections = implode($this->Loader->L10N->getString('grammar_spacer'), $this->Loader->ScanResultsText);

            /** A fix for correctly displaying LTR/RTL text. */
            if ($this->Loader->L10N->getString('Text Direction') !== 'rtl') {
                $this->Loader->L10N->Data['Text Direction'] = 'ltr';
            }

            /** Merging parsable variables for the template data. */
            $TemplateData = [
                'magnification' => $this->Loader->Configuration['web']['magnification'],
                'Attache' => $this->Attache,
                'detected' => $Detections,
                'phpmusselversion' => $this->Loader->ScriptIdent,
                'favicon' => base64_encode($this->Loader->getFavicon()),
                'xmlLang' => $this->Loader->Configuration['core']['lang']
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
            if (is_readable($this->AssetsPath . 'template_' . $this->Loader->Configuration['web']['theme'] . '.html')) {
                $TemplateFile = $this->AssetsPath . 'template_' . $this->Loader->Configuration['web']['theme'] . '.html';
            } elseif (is_readable($this->AssetsPath . 'template_default.html')) {
                $TemplateFile = $this->AssetsPath . 'template_default.html';
            } else {
                $TemplateFile = '';
            }

            /** Log "uploads_log" data. */
            if (strlen($this->Loader->HashReference) !== 0) {
                $Handle['Data'] = sprintf(
                    "%s: %s\n%s: %s\n== %s ==\n%s\n== %s ==\n%s",
                    $this->Loader->L10N->getString('field_date'),
                    $this->Loader->timeFormat($this->Loader->Time, $this->Loader->Configuration['core']['time_format']),
                    $this->Loader->L10N->getString('field_ip_address'),
                    ($this->Loader->Configuration['legal']['pseudonymise_ip_addresses'] ? $this->Loader->pseudonymiseIP(
                        $_SERVER[$this->Loader->Configuration['core']['ipaddr']]
                    ) : $_SERVER[$this->Loader->Configuration['core']['ipaddr']]),
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
                die('[phpMussel] ' . $this->Loader->L10N->getString('denied') . ' ' . $TemplateData['detected']);
            }

            /** Send a 403 FORBIDDEN status code to the client if "forbid_on_block" is enabled. */
            if ($this->Loader->Configuration['web']['forbid_on_block']) {
                header('HTTP/1.0 403 Forbidden');
                header('HTTP/1.1 403 Forbidden');
                header('Status: 403 Forbidden');
            }

            /** Include privacy policy. */
            $TemplateData['pp'] = empty(
                $this->Loader->Configuration['legal']['privacy_policy']
            ) ? '' : '<br /><a href="' . $this->Loader->Configuration['legal']['privacy_policy'] . '">' . $this->Loader->L10N->getString('PrivacyPolicy') . '</a>';

            /** Generate HTML output. */
            $Output = $this->Loader->parse(
                $this->Loader->L10N->Data,
                $this->Loader->parse($TemplateData, $this->Loader->readFile($TemplateFile))
            );

            /** Before output event. */
            $this->Loader->Events->fireEvent('beforeOutput', '', $Output);

            /** Send HTML output and the kill the script. */
            die($Output);
        }
    }
}
