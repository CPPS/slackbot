<?php
// This file contains configurations for the bot.php script
// 	that sends messages to Slack about Domjudge
// Copy this file to config.php and fill in the fields

// Check only the slack bot loads this config
if (! isset($CONFIG_IMPORTING_INTO) || $CONFIG_IMPORTING_INTO !== "slackbot") {
	die();
}

// Information on the database this bots saves its info in
$DB_HOST = "localhost";
$DB_USER = "";
$DB_PASS = "";
$DB_DATABASE = "";
$DB_TABLE = "slack";

// Information on the Database of the Domjudge to connect to
// NB: This is the Domjudge Database, NOT the Domjudge interface
$DOMJUDGE_HOST = "";
$DOMJUDGE_USER = "";
$DOMJUDGE_PASS = "";
$DOMJUDGE_DATABASE = "domjudge";

// Information needed to send messages to Slack
$SLACK_HOOK = "https://hooks.slack.com/services/XXXXXXXXX/XXXXXXXXX/XXXXXXXXXXXXXXXXXXXXXXXX";

// Other (non-confidential) settings
$LENGTH = 60; // Length this script runs for in seconds
$INTERVAL = 10; // Time between loops
