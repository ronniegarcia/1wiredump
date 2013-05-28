#!/usr/bin/php
<?php

# Copyright 2013, Ronnie Garcia, OVEA, http://www.ovea.com
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.
#

require_once 'Console/CommandLine.php';

// parse command line options
$parser = new Console_CommandLine();
$parser->description = 'A script to write and read to a 1-wire bus. It needs a serial adapter connected to this computer';
$parser->version = '1.0';

$parser->addOption('tty', array(
  'short_name'  => '-s',
  'long_name'   => '--serial',
  'description' => 'Serial port to use, defaults to /dev/ttyS0',
  'default'     => '/dev/ttyS0',
  'help_name'   => 'TTY',
  'action'      => 'StoreString'
));

$parser->addOption('linknx', array(
  'short_name'  => '-l',
  'long_name'   => '--linknx',
  'description' => 'Outputs linknx <write> XML format, to be sent with netcat for example',
  'action'      => 'StoreTrue'
));

$parser->addOption('debug', array(
  'short_name'  => '-d',
  'long_name'   => '--debug',
  'description' => 'Debug mode, outputs bus dialog',
  'action'      => 'StoreTrue'
));

try
{
  $options = $parser->parse();

  $serial = fopen($options->options['tty'], 'w+b');
  if (!$serial)
    die('Enable to open ' . $options->options['tty'] . "\n");

  // is it really usefull ?
  owWrite($serial, ' ');
  $return = owRead($serial);
  debug($return);

  $sensors = array();
  owWrite($serial, 'f');
  while ($return = owRead($serial))
  {
    // convert output to sensor id string
    $sensor_id = preg_replace('/^..(..)(..)(..)(..)(..)(..)(..)(..)/', '$8$7$6$5$4$3$2$1', $return);
//    array_push($sensors, $sensor_id);
    $sensors[$sensor_id] = '';
    if ('-' == substr($return, 0, 1))
      break;
    owWrite($serial, 'n');
  }

  foreach ($sensors as $sensor_id => $nullvalue)
  {
    if ('28' == substr($sensor_id, 0, 2))
    {
      // this is a temperature sensor
      $temp = getTemp($serial, $sensor_id);
      $sensors[$sensor_id] = $temp;
    }
  }

  if (TRUE == $options->options['linknx'])
  {
    echo '<write>';
    foreach ($sensors as $sensor_id => $temp)
      echo '<object id="' . $sensor_id . '" value="' . $temp . '"/>';
    echo '</write>';
    echo '\4';
    echo "\n";
  }
  else
  {
    print_r($sensors);
  }

  fclose($serial);

}
catch (Exception $exc)
{
  $parser->displayError($exc->getMessage());
}

function debug($message)
{
  global $options;
  if (TRUE == $options->options['debug'])
    echo $message . "\n";
}

function owReset($handle)
{
  owWrite($handle, 'r');
  owRead($handle);
}

function getTemp($handle, $sensor_id)
{
  owReset($handle);
  $query = 'b55' . $sensor_id . '44';
  owWrite($handle, $query);
  owRead($handle);
  sleep(2);
  owReset($handle);
  $query = 'b55' . $sensor_id . 'BEFFFFFFFFFFFF';
  owWrite($handle, $query);
  $string = owRead($handle);

  // extract temperature from reply
  $lsb = substr($string, 20, 2);
  $msb = substr($string, 22, 2);
  $temp = ((hexdec($msb) << 8) | hexdec($lsb)) / 16;

  return $temp;
}

function owRead($handle)
{
  $return = '';

  // read, char by char, until end prompt
  while ("?" !== ($char = fgetc($handle)))
  {
    $return .= $char;
    // echo "$char (" . ord($char) . ")\n";
  }
  // remove both trailing control characters
  $return = substr($return, 0, -2);
  debug("READ $return");
  return $return;
}

function owWrite($handle, $data)
{
  debug("SEND $data");
  $data = $data . "\r\n";
  $return = fputs($handle, $data);
  return $return;
}

?>
