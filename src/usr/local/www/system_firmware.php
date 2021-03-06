<?php
/* $Id$ */
/*
	system_firmware.php
	Copyright (C) 2008 Scott Ullrich <sullrich@gmail.com>
	Copyright (C) 2013-2015 Electric Sheep Fencing, LP
	All rights reserved.

	originally part of m0n0wall (http://m0n0.ch/wall)
	Copyright (C) 2003-2004 Manuel Kasper <mk@neon1.net>.
	All rights reserved.

	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions are met:

	1. Redistributions of source code must retain the above copyright notice,
	   this list of conditions and the following disclaimer.

	2. Redistributions in binary form must reproduce the above copyright
	   notice, this list of conditions and the following disclaimer in the
	   documentation and/or other materials provided with the distribution.

	THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
	AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
	AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
	OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
	SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
	INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
	CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
	ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
	POSSIBILITY OF SUCH DAMAGE.
*/
/*
	pfSense_BUILDER_BINARIES:	/usr/bin/tar
	pfSense_MODULE: firmware
*/

##|+PRIV
##|*IDENT=page-system-firmware-manualupdate
##|*NAME=System: Firmware: Manual Update page
##|*DESCR=Allow access to the 'System: Firmware: Manual Update' page.
##|*MATCH=system_firmware.php*
##|-PRIV

$d_isfwfile = 1;
$nocsrf = true;

require_once("globals.inc");
require_once("functions.inc");
require_once("guiconfig.inc");
require_once("xmlrpc_client.inc");

$curcfg = $config['system']['firmware'];

/* Allow additional execution time 0 = no limit. */
ini_set('max_execution_time', '9999');
ini_set('max_input_time', '9999');

function file_is_for_platform($filename, $ul_name) {
	global $g;
	if ($g['platform'] == "nanobsd") {
		if (stristr($ul_name, "nanobsd")) {
			return true;
		} else {
			return false;
		}
	}
	$_gb = exec("/usr/bin/tar xzf $filename -C /tmp/ etc/platform");
	unset($_gb);
	if (!file_exists("/tmp/etc/platform")) {
		return false;
	}
	$upgrade_is_for_platform = trim(file_get_contents("/tmp/etc/platform", " \n\t\r"));
	if ($g['platform'] == $upgrade_is_for_platform) {
		@unlink("/tmp/etc/platform");
		return true;
	}
	return false;
}

function file_upload_error_message($error_code) {
	switch ($error_code) {
		case UPLOAD_ERR_INI_SIZE:
			return gettext('The uploaded file exceeds the upload_max_filesize directive in php.ini');
		case UPLOAD_ERR_FORM_SIZE:
			return gettext('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form');
		case UPLOAD_ERR_PARTIAL:
			return gettext('The uploaded file was only partially uploaded');
		case UPLOAD_ERR_NO_FILE:
			return gettext('No file was uploaded');
		case UPLOAD_ERR_NO_TMP_DIR:
			return gettext('Missing a temporary folder');
		case UPLOAD_ERR_CANT_WRITE:
			return gettext('Failed to write file to disk');
		case UPLOAD_ERR_EXTENSION:
			return gettext('File upload stopped by extension');
		default:
			return gettext('Unknown upload error');
	}
}

/* if upgrade in progress, alert user */
if (is_subsystem_dirty('firmwarelock')) {
	$pgtitle = array(gettext("System"), gettext("Firmware"), gettext("Manual Update"));
	include("head.inc");
	include("fbegin.inc");
	print_info_box(gettext("An upgrade is currently in progress. The firewall will reboot when the operation is complete.") . "<p><img src='/themes/{$g['theme']}/images/icons/icon_fw-update.gif' alt='update' /></p>");
	include("foot.inc");
	exit;
}

if ($_POST['backupbeforeupgrade']) {
	touch("/tmp/perform_full_backup.txt");
}

/* Handle manual upgrade */
if ($_POST && !is_subsystem_dirty('firmwarelock')) {

	unset($input_errors);
	unset($sig_warning);

	if (stristr($_POST['Submit'], gettext("Enable"))) {
		$mode = "enable";
	} else if (stristr($_POST['Submit'], gettext("Disable"))) {
		$mode = "disable";
	} else if (stristr($_POST['Submit'], gettext("Upgrade")) || $_POST['sig_override']) {
		$mode = "upgrade";
	} else if ($_POST['sig_no']) {
		if (file_exists("{$g['upload_path']}/firmware.tgz")) {
			unlink("{$g['upload_path']}/firmware.tgz");
		}
	}
	if ($mode) {
		if ($mode == "enable") {
			conf_mount_rw();
			mark_subsystem_dirty('firmware');
		} else if ($mode == "disable") {
			conf_mount_ro();
			clear_subsystem_dirty('firmware');
		} else if ($mode == "upgrade") {
			if ($_FILES['ulfile']['error']) {
				$errortext = "(" . file_upload_error_message($_FILES['ulfile']['error']) . ")";
			}
			if (is_uploaded_file($_FILES['ulfile']['tmp_name'])) {
				/* verify firmware image(s) */
				if (file_is_for_platform($_FILES['ulfile']['tmp_name'], $_FILES['ulfile']['name']) == false && !$_POST['sig_override']) {
					$input_errors[] = gettext("The uploaded image file is not for this platform.");
				} else if (!file_exists($_FILES['ulfile']['tmp_name'])) {
					/* probably out of memory for the MFS */
					$input_errors[] = gettext("Image upload failed (out of memory?)");
					mwexec("/etc/rc.firmware disable");
					clear_subsystem_dirty('firmware');
				} else {
					/* move the image so PHP won't delete it */
					rename($_FILES['ulfile']['tmp_name'], "{$g['upload_path']}/firmware.tgz");

					/* check digital signature */
					$sigchk = verify_digital_signature("{$g['upload_path']}/firmware.tgz");

					if ($sigchk == 1) {
						$sig_warning = gettext("The digital signature on this image is invalid.");
					} else if ($sigchk == 2 && !isset($config['system']['firmware']['allowinvalidsig'])) {
						$sig_warning = gettext("This image is not digitally signed.");
					} else if (($sigchk >= 3)) {
						$sig_warning = gettext("There has been an error verifying the signature on this image.");
					}

					if (!verify_gzip_file("{$g['upload_path']}/firmware.tgz")) {
						$input_errors[] = gettext("The image file is corrupt.");
						unlink("{$g['upload_path']}/firmware.tgz");
					}
				}
			}

			run_plugins("/usr/local/pkg/firmware_upgrade");

			/* Check for input errors, firmware locks, warnings, then check for firmware if sig_override is set */
			if (!$input_errors && !is_subsystem_dirty('firmwarelock') && (!$sig_warning || $_POST['sig_override'])) {
				if (file_exists("{$g['upload_path']}/firmware.tgz")) {
					/* fire up the update script in the background */
					mark_subsystem_dirty('firmwarelock');
					$savemsg = gettext("The firmware is now being updated. The firewall will reboot automatically.");
					if (stristr($_FILES['ulfile']['name'], "nanobsd") or $_POST['isnano'] == "yes") {
						mwexec_bg("/etc/rc.firmware pfSenseNanoBSDupgrade {$g['upload_path']}/firmware.tgz");
					} else {
						if ($g['platform'] == "nanobsd") {
							$whichone = "pfSenseNanoBSDupgrade";
						} else {
							$whichone = "pfSenseupgrade";
						}
						mwexec_bg("/etc/rc.firmware {$whichone} {$g['upload_path']}/firmware.tgz");
						unset($whichone);
					}
				} else {
					$savemsg = sprintf(gettext("Firmware image missing or other error, please try again %s."), $errortext);
				}
			}
		}
	}
}

$pgtitle = array(gettext("System"), gettext("Firmware"));
include("head.inc");

if ($input_errors)
	print_input_errors($input_errors);

if ($savemsg)
	print_info_box($savemsg);

if ($fwinfo != "")
	print_info_box($fwinfo);

$tab_array = array();
$tab_array[] = array(gettext("Manual Update"), true, "system_firmware.php");
$tab_array[] = array(gettext("Auto Update"), false, "system_firmware_check.php");
$tab_array[] = array(gettext("Updater Settings"), false, "system_firmware_settings.php");
if($g['hidedownloadbackup'] == false)
	$tab_array[] = array(gettext("Restore Full Backup"), false, "system_firmware_restorefullbackup.php");

display_top_tabs($tab_array);

// For a simple yes/no we can use an HTML form
if ($sig_warning && !$input_errors) {
	$sig_warning = gettext("The image you uploaded " .
		"is not an official/supported image and may lead to unexpected behavior or security " .
		"compromises. Only install images that come from sources that you trust, and make sure ".
		"that the image has not been tampered with.") . "<br /><br />".
		gettext("Do you want to install this image anyway (on your own risk)?");

	print_info_box($sig_warning);
?>
	<form action="system_firmware.php" method="post" enctype="multipart/form-data">
		<input name="sig_override" type="submit" class="btn btn-danger" id="sig_override" value=" <?=gettext("Yes");?> " />
		<input name="sig_no" type="submit" class="btn btn-default" id="sig_no" value=" <?=gettext("No"); ?> " />
	</form>
<?php

} else {
	// This is where the work gets done so Forms.classes will be used from this point
	if (!is_subsystem_dirty('firmwarelock')) {
		require_once('classes/Form.class.php');

		if (!is_subsystem_dirty('rebootreq')) {
			// Provide a button to enable firmware upgrades. Upgrades should be disabled on initial page load
			if (!is_subsystem_dirty('firmware') || !$_POST || $_POST['save']) {
				$enablebtn = new Form_Button(
					'Submit',
					'Enable firmware upload'
					);

				$enablebtn->addClass('btn-warning');
				$form = new Form($enablebtn);
				$section = new Form_Section('Invoke ' . $g['product_name'] .' Manual Upgrade');
				$section->addInput(new Form_StaticText('Enable', 'Click the "Enable firmware upload" button below to begin.'));
			}
			else {
				// Upgrades are now enabled
				$form = new Form('Disable firmware upload');

				$form->setMultipartEncoding();

				$section = new Form_Section('Perform ' . $g['product_name'] .' Manual Upgrade');

				if (!session_id())
					$upload_id = uniqid();
				else
					$upload_id = session_id();

				$section->addInput(new Form_Input(
					'UPLOAD_IDENTIFIER',
					'',
					'hidden',
					$upload_id
					));

				if(stristr($_FILES['ulfile']['name'],"nanobsd")) {
					$section->addInput(new Form_Input(
						'isnano',
						'',
						'hidden',
						'yes'
						));
				}

				if ($g['platform'] == "nanobsd")
					$type = "*.img.gz";
				else
					$type = "*.tgz";

				$filepicker = new Form_Input(
					'ulfile',
					'File to upload (' . $type . ')',
					'file',
					''
				);

				$section->addInput($filepicker)->setHelp('Choose the file you wish to upload');

				if ($g['hidebackupbeforeupgrade'] === false) {
					$section->addInput(new Form_Checkbox(
						'backupbeforeupgrade',
						Backup,
						'Perform a full backup prior to upgrade',
						false
					));
				}

				$section->addInput(new Form_Button(
					'submit',
					'Upgrade firmware'
				))->addClass('btn-danger btn-sm')->setHelp('Click the "Upgrade firmware" button above to start the upgrade process');
			}

			$form->add($section);
			print($form);
		}
	}
	else {
		print_info_box('<strong>' . gettext("You must reboot the system before you can upgrade the firmware.") . '</strong>');
	}

	if (is_subsystem_dirty('firmware') && !is_subsystem_dirty('firmwarelock')) {
		print_info_box('<strong>' . gettext("DO NOT ") . '</strong>' . gettext('abort the firmware upgrade once it ' .
			'has started. The firewall will reboot automatically after ' .
			'storing the new firmware. The configuration will be maintained.'));
	}
}

include("foot.inc"); ?>