#!/usr/bin/php
<?php

	$hosts_file 				= '/etc/hosts';
	$config_file				= '/etc/vhosts.conf';
	$apache_sites_file	= '/etc/apache2/sites-enabled/vhosts.conf';
	$apache_restart_cmd = '/etc/init.d/apache2 restart';
	$install_path				= '/usr/local/bin/vhost';

	function println($txt = ''){
		echo '  '.$txt.PHP_EOL;
	}

	println();

	function print_help(){
		println('Usage:');
		println('  '.basename(__FILE__).' help');
		println('  '.basename(__FILE__).' install');
		println('  '.basename(__FILE__).' list');
		println('  '.basename(__FILE__).' add <document_root> <server_name> [server_alias] [server_alias]...');
		println('  '.basename(__FILE__).' enable <server_name>');
		println('  '.basename(__FILE__).' disable <server_name>');
		println('  '.basename(__FILE__).' delete|remove <server_name>');
		println('  '.basename(__FILE__).' restart');
	}

	function print_error($txt){
		println($txt);
		println();
		exit();
	}

	function print_syntax_error(){
		println('Syntax error');
		println();
		print_help();
		println();
		exit();
	}

	if(posix_geteuid() != 0) print_error('You need root privileves');

	$vhosts = [];

	if(!file_exists($config_file)) file_put_contents($config_file, json_encode($vhosts));
	$vhosts = json_decode(file_get_contents($config_file), true);

	// TODO: check vhosts.conf file integrity

	if($argc <= 1) $argv[1] = 'help';
	switch($argv[1]){

		case 'help':
			print_help();
			println();
			exit();
		break;

		case 'list':
			if(count($vhosts) > 0){
				for($i=0; $i<count($vhosts); $i++){
					if($i > 0) println();
					println('Server name   : '.$vhosts[$i]['server_name']);
					println('Server alias  : '.$vhosts[$i]['server_alias']);
					println('Document root : '.$vhosts[$i]['document_root']);
					println('Enabled       : '.$vhosts[$i]['enabled']);
				}
			} else {
				print_error('There is no vhost configured');
			}
		break;

		case 'add':
			if($argc < 4) print_syntax_error();

			$vhost = [];
			$vhost['document_root'] = trim($argv[2]);
			$vhost['server_name'] 	= trim($argv[3]);
			$vhost['server_alias'] 	= [];
			$vhost['enabled'] 			= 'yes';

			if($argc >= 5){
				for($i=4; $i<$argc; $i++){
					$vhost['server_alias'][] = trim($argv[$i]);
				}
			}
			$vhost['server_alias'] = implode(' ', $vhost['server_alias']);

			$found = [];
			for($i=0; $i<count($vhosts); $i++){
				if($vhost['server_name'] == $vhosts[$i]['server_name'] || in_array($vhost['server_name'], explode(' ', $vhosts[$i]['server_alias']))){
					$found[$vhost['server_name']] = $vhost['server_name'];
				}
				foreach(explode(' ', $vhost['server_alias']) as $server_alias){
					if($server_alias == $vhosts[$i]['server_name'] || in_array($server_alias, explode(' ', $vhosts[$i]['server_alias']))){
						$found[$server_alias] = $server_alias;
					}
				}
			}

			if(count($found) > 0) print_error(implode(' ', $found).' in use in other vhost');
			else {
				$vhosts[] = $vhost;
				file_put_contents($config_file, json_encode($vhosts));
				println('vhost added!');
			}

		break;

		case 'delete':
		case 'remove':
			if($argc != 3) print_syntax_error();

			$tmp = [];
			$found = false;
			foreach($vhosts as $vhost){
				if($vhost['server_name'] == $argv[2]) $found = true;
				else $tmp[] = $vhost;
			}

			if(!$found) print_error('vhost not found');
			else {
				$vhosts = $tmp;
				file_put_contents($config_file, json_encode($vhosts));
				println('vhost deleted!');
			}

		break;

		case 'enable':
			if($argc != 3) print_syntax_error();

			$found = false;
			for($i=0; $i<count($vhosts); $i++){
				if($vhosts[$i]['server_name'] == $argv[2]){
					$vhosts[$i]['enabled'] = 'yes';
					$found = true;
				}
			}

			if(!$found) print_error('vhost not found');
			else {
				file_put_contents($config_file, json_encode($vhosts));
				println('vhost enabled!');
			}

		break;

		case 'disable':
			if($argc != 3) print_syntax_error();

			$found = false;
			for($i=0; $i<count($vhosts); $i++){
				if($vhosts[$i]['server_name'] == $argv[2]){
					$vhosts[$i]['enabled'] = 'no';
					$found = true;
				}
			}

			if(!$found) print_error('vhost not found');
			else {
				file_put_contents($config_file, json_encode($vhosts));
				println('vhost disabled!');
			}

		break;

		case 'restart':
			if($argc != 2) print_syntax_error();

			println('updating hosts file '.$hosts_file.' ...');

			$hosts_line = [];
			foreach($vhosts as $vhost){
				if($vhost['enabled'] == 'yes'){
					$hosts_line[] = $vhost['server_name'];
					$hosts_line[] = $vhost['server_alias'];
				}
			}
			if(count($hosts_line) > 0) $hosts_line = '127.0.0.1 '.implode(' ', $hosts_line);
			else $hosts_line = '';

			$start = '### vhosts start ###';
			$end   = '###  vhosts end  ###';

			$hosts = file_get_contents($hosts_file);
			if(strpos($hosts, $start) === false){
				$hosts .= "\n\n".$start."\n".$end."\n";
				copy($hosts_file, $hosts_file.'.vhosts.bak');
			}

			$hosts = preg_replace('/'.$start.'.*'.$end.'/ismU', $start."\n".$hosts_line."\n".$end, $hosts);
			file_put_contents($hosts_file, $hosts);

			println('updating apache sites file '.$apache_sites_file.' ...');

			$sites = [];
			foreach($vhosts as $vhost){
				if($vhost['enabled'] == 'yes'){
					$sites[] = '<VirtualHost *:80>';
					$sites[] = '  ServerName '.$vhost['server_name'];
					if($vhost['server_alias'] != '') $sites[] = '  ServerAlias '.$vhost['server_alias'];
					$sites[] = '  DocumentRoot '.$vhost['document_root'];
					$sites[] = '  ErrorLog ${APACHE_LOG_DIR}/'.$vhost['server_name'].'error.log';
					$sites[] = '  CustomLog ${APACHE_LOG_DIR}/'.$vhost['server_name'].'.access.log combined';
					$sites[] = '</VirtualHost>';
					$sites[] = '';
				}
				$sites[] = '';
			}

			file_put_contents($apache_sites_file, implode("\n", $sites));

			println('restarting apache ...');
			exec($apache_restart_cmd);

		break;

		case 'install':
			if($argc != 2) print_syntax_error();
			println('copying file ...');
			copy(__FILE__, $install_path);
			println('applying permissions ...');
			chmod($install_path, 0755);
			println('installed!');
		break;

		default:
			print_error('unknown command');

	}

	println();
