<?php

/**
 * Copyright (c) 2012-2013, Mollie B.V.
 * All rights reserved. 
 * 
 * Redistribution and use in source and binary forms, with or without 
 * modification, are permitted provided that the following conditions are met: 
 * 
 * - Redistributions of source code must retain the above copyright notice, 
 *    this list of conditions and the following disclaimer.
 * - Redistributions in binary form must reproduce the above copyright 
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR AND CONTRIBUTORS ``AS IS'' AND ANY 
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED 
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE 
 * DISCLAIMED. IN NO EVENT SHALL THE AUTHOR OR CONTRIBUTORS BE LIABLE FOR ANY 
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES 
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR 
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY 
 * OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH 
 * DAMAGE. 
 * 
 * ----------------------------------------------------------------------------------------------------
 *
 * @category    Mollie
 * @package     Mollie_Mpm
 * @author      Mollie B.V. (info@mollie.nl)
 * @version     v3.15.0
 * @copyright   Copyright (c) 2012-2013 Mollie B.V. (https://www.mollie.nl)
 * @license     http://www.opensource.org/licenses/bsd-license.php  Berkeley Software Distribution License (BSD-License 2)
 * 
 * ----------------------------------------------------------------------------------------------------
 *
 * Start              : 24 februari 2009
 * Door               : Mollie B.V. (RDF) ¬© 2009
 * Versie             : 1.13 (gebaseerd op de Mollie iDEAL class van Concepto IT Solution - http://www.concepto.nl/)
 * Laatste aanpassing : 18-04-2011
 * Aard v. aanpassing : Ondersteuning voor het nieuwe 'status' veld
 * Door               : MK
 *
 **/

class Mollie_Mpm_Helper_Idl
{

	const MIN_TRANS_AMOUNT = 118;

	protected $partner_id = null;
	protected $profile_key = null;
	protected $testmode = false;
	protected $bank_id = null;
	protected $amount = 0;
	protected $description = null;
	protected $return_url = null;
	protected $report_url = null;
	protected $bank_url = null;
	protected $payment_url = null;
	protected $transaction_id = null;
	protected $paid_status = false;
	protected $status= '';
	protected $consumer_info = array();
	protected $error_message = '';
	protected $error_code = 0;
	protected $api_host = 'ssl://secure.mollie.nl';
	protected $api_port = 443;

	public function __construct($api_host = 'ssl://secure.mollie.nl', $api_port = 443)
	{
		$this->api_host = $api_host;
		$this->api_port = $api_port;

		// Set Partner id
		$this->setPartnerId(Mage::Helper('mpm/data')->getPartnerid());
		// Set Profile-key
		$this->setProfileKey(Mage::Helper('mpm/data')->getProfilekey());
		// Set Testmode
		$this->setTestmode(Mage::Helper('mpm/data')->getTestModeEnabled());
	}

	/**
	 * Haal de lijst van beschikbare banken
	 *
	 * @return array[]
	 */
	public function getBanks()
	{
		$query_variables = array(
			'a' => 'banklist',
			'partner_id' => $this->partner_id,
		);

		if ($this->testmode)
		{
			$query_variables['testmode'] = 'true';
		}

		$banks_xml = $this->_sendRequest(
				$this->api_host, $this->api_port, '/xml/ideal/', http_build_query($query_variables, '', '&')
		);

		if (empty($banks_xml))
		{
			return false;
		}

		$banks_object = $this->_XMLtoObject($banks_xml);

		if (!$banks_object or $this->_XMlisError($banks_object))
		{
			$this->error_message = "Geen XML of XML met API fout ontvangen van Mollie";
			return false;
		}

		$banks_array = array();

		foreach ($banks_object->bank as $bank)
		{
			$banks_array["{$bank->bank_id}"] = "{$bank->bank_name}";
		}

		return $banks_array;
	}

	/**
	 * Zet een betaling klaar bij de bank en maak de betalings URL beschikbaar
	 *
	 * @return boolean
	 */
	public function createPayment($bank_id, $amount, $description, $return_url, $report_url)
	{
		if (!$this->setBankId($bank_id))
		{
			$this->error_message = "De opgegeven bank \"$bank_id\" is onjuist of incompleet";
			return false;
		}

		if (!$this->setDescription($description))
		{
			$this->error_message = "De opgegeven omschrijving \"$description\" is incompleet";
			return false;
		}

		if (!$this->setAmount($amount))
		{
			$this->error_message = "Het opgegeven bedrag \"$amount\" is onjuist of te laag";
			return false;
		}

		if (!$this->setReturnURL($return_url))
		{
			$this->error_message = "De opgegeven return URL \"$return_url\" is onjuist";
			return false;
		}

		if (!$this->setReportURL($report_url))
		{
			$this->error_message = "De opgegeven report URL \"$report_url\" is onjuist";
			return false;
		}

		$query_variables = array(
			'a' => 'fetch',
			'partnerid' => $this->getPartnerId(),
			'bank_id' => $this->getBankId(),
			'amount' => $this->getAmount(),
			'description' => $this->getDescription(),
			'reporturl' => $this->getReportURL(),
			'returnurl' => $this->getReturnURL(),
		);

		if ($this->profile_key)
			$query_variables['profile_key'] = $this->profile_key;

		$create_xml = $this->_sendRequest(
				$this->api_host, $this->api_port, '/xml/ideal/', http_build_query($query_variables, '', '&')
		);

		if (empty($create_xml))
		{
			return false;
		}

		$create_object = $this->_XMLtoObject($create_xml);

		if (!$create_object or $this->_XMLisError($create_object))
		{
			return false;
		}

		$this->transaction_id = (string) $create_object->order->transaction_id;
		$this->bank_url = (string) $create_object->order->URL;

		return true;
	}

	// Kijk of er daadwerkelijk betaald is
	public function checkPayment($transaction_id)
	{
		if (!$this->setTransactionId($transaction_id))
		{
			$this->error_message = "Er is een onjuist transactie ID opgegeven";
			return false;
		}

		$query_variables = array(
			'a' => 'check',
			'partnerid' => $this->partner_id,
			'transaction_id' => $this->getTransactionId(),
		);

		if ($this->testmode)
		{
			$query_variables['testmode'] = 'true';
		}

		$check_xml = $this->_sendRequest(
				$this->api_host, $this->api_port, '/xml/ideal/', http_build_query($query_variables, '', '&')
		);

		if (empty($check_xml))
			return false;

		$check_object = $this->_XMLtoObject($check_xml);

		if (!$check_object or $this->_XMLisError($check_object))
		{
			return false;
		}

		$this->paid_status = (bool) ($check_object->order->payed == 'true');
		$this->status = (string) $check_object->order->status;
		$this->amount = (int) $check_object->order->amount;
		$this->consumer_info = (isset($check_object->order->consumer)) ? (array) $check_object->order->consumer : array();

		return true;
	}

	public function CreatePaymentLink($description, $amount)
	{
		if (!$this->setDescription($description) or !$this->setAmount($amount))
		{
			$this->error_message = "U moet een omschrijving √©n bedrag (in centen) opgeven voor de iDEAL link. Tevens moet het bedrag minstens " . self::MIN_TRANS_AMOUNT . ' eurocent zijn. U gaf ' . (int) $amount . ' cent op.';
			return false;
		}

		$query_variables = array(
			'a' => 'create-link',
			'partnerid' => $this->partner_id,
			'amount' => $this->getAmount(),
			'description' => $this->getDescription(),
		);

		$create_xml = $this->_sendRequest(
				$this->api_host, $this->api_port, '/xml/ideal/', http_build_query($query_variables, '', '&')
		);

		$create_object = $this->_XMLtoObject($create_xml);

		if (!$create_object or $this->_XMLisError($create_object))
		{
			return false;
		}

		$this->payment_url = (string) $create_object->link->URL;
	}

	/*
	  PROTECTED FUNCTIONS
	 */

	protected function _sendRequest($host, $port, $path, $data)
	{
		if (function_exists('curl_init'))
		{
			return $this->_sendRequestCurl($host, $port, $path, $data);
		} else
		{
			return $this->_sendRequestFsock($host, $port, $path, $data);
		}
	}

	protected function _sendRequestFsock($host, $port, $path, $data)
	{
		$hostname = str_replace('ssl://', '', $host);
		$fp = @fsockopen($host, $port, $errno, $errstr);
		$buf = '';

		if (!$fp)
		{
			$this->error_message = 'Kon geen verbinding maken met server: ' . $errstr;
			$this->error_code = 0;

			return false;
		}

		@fputs($fp, "POST $path HTTP/1.0\n");
		@fputs($fp, "Host: $hostname\n");
		@fputs($fp, "Content-type: application/x-www-form-urlencoded\n");
		@fputs($fp, "Content-length: " . strlen($data) . "\n");
		@fputs($fp, "Connection: close\n\n");
		@fputs($fp, $data);

		while (!feof($fp))
		{
			$buf .= fgets($fp, 128);
		}

		fclose($fp);

		if (empty($buf))
		{
			$this->error_message = 'Zero-sized reply';
			return false;
		} else
		{
			list($headers, $body) = preg_split("/(\r?\n){2}/", $buf, 2);
		}

		return $body;
	}

	protected function _sendRequestCurl($host, $port, $path, $data)
	{
		$host = str_replace('ssl://', 'https://', $host);

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $host . $path);
		curl_setopt($ch, CURLOPT_PORT, $port);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_ENCODING, ""); // Tell server which Encodings (gzip, deflate) we support.

		$body = curl_exec($ch);

		if (curl_errno($ch) == CURLE_SSL_CACERT)
		{
			/*
			 * On some servers, the list of installed certificates is outdated or not present at all (the ca-bundle.crt
			 * is not installed). So we tell cURL which certificates we trust. Then we retry the requests.
			 */
			curl_setopt($ch, CURLOPT_CAINFO, dirname(__FILE__) . DIRECTORY_SEPARATOR . "cacert.pem");
			$body = curl_exec($ch);
		}

		if (strpos(curl_error($ch), "certificate subject name 'mollie.nl' does not match target host") !== FALSE)
		{
			/*
			 * On some servers, the wildcard SSL certificate is not processed correctly. This happens with OpenSSL 0.9.7
			 * from 2003.
			 */
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
			$body = curl_exec($ch);
		}

		if (curl_error($ch))
		{
			$this->error_message = "Fout bij communiceren met Mollie: " . curl_error($ch);
			$this->error_code    = curl_errno($ch);
		}

		curl_close($ch);

		return $body;
	}

	protected function _XMLtoObject($xml)
	{
		try
		{
			$xml_object = @simplexml_load_string($xml);
			if (!$xml_object)
			{
				$this->error_code    = -2;
				$this->error_message = "Kon XML resultaat niet verwerken";
				return false;
			}
		} catch (Exception $e)
		{
			$this->error_code    = -2;
			$this->error_message = $e->getMessage();
			return false;
		}

		return $xml_object;
	}

	protected function _XMLisError(SimpleXMLElement $xml)
	{
		if (isset($xml->item))
		{
			$attributes = $xml->item->attributes();
			if ($attributes['type'] == 'error')
			{
				$this->error_message = (string) $xml->item->message;
				$this->error_code = (string) $xml->item->errorcode;

				return true;
			}
		}

		if (isset($xml->order->error) && (string) $xml->order->error == "true") {
			$this->error_message = $xml->order->message;
			$this->error_code = -1;
			return true;
		}

		return false;
	}

	/* Getters en setters */

	public function setProfileKey($profile_key)
	{
		if (is_null($profile_key))
			return false;

		return ($this->profile_key = $profile_key);
	}

	public function getProfileKey()
	{
		return $this->profile_key;
	}

	public function setPartnerId($partner_id)
	{
		if (!is_numeric($partner_id))
		{
			return false;
		}

		return ($this->partner_id = $partner_id);
	}

	public function getPartnerId()
	{
		return $this->partner_id;
	}

	public function setTestmode($enable = true)
	{
		return ($this->testmode = $enable);
	}

	public function setBankId($bank_id)
	{
		if (!is_numeric($bank_id))
			return false;

		return ($this->bank_id = $bank_id);
	}

	public function getBankId()
	{
		return $this->bank_id;
	}

	public function setAmount($amount)
	{
		if (!preg_match('~^[0-9]+$~', $amount))
		{
			return false;
		}

		if (self::MIN_TRANS_AMOUNT > $amount)
		{
			return false;
		}

		return ($this->amount = $amount);
	}

	public function getAmount()
	{
		return $this->amount;
	}

	public function setDescription ($description)
	{
		$description = function_exists("mb_substr") ? mb_substr($description, 0, 29) : substr($description, 0, 29);

		return ($this->description = $description);
	}

	public function getDescription()
	{
		return $this->description;
	}

	public function setReturnURL($return_url)
	{
		if (!preg_match('|(\w+)://([^/:]+)(:\d+)?(.*)|', $return_url))
			return false;

		return ($this->return_url = $return_url);
	}

	public function getReturnURL()
	{
		return $this->return_url;
	}

	public function setReportURL($report_url)
	{
		if (!preg_match('|(\w+)://([^/:]+)(:\d+)?(.*)|', $report_url))
		{
			return false;
		}

		return ($this->report_url = $report_url);
	}

	public function getReportURL()
	{
		return $this->report_url;
	}

	public function setTransactionId($transaction_id)
	{
		if (empty($transaction_id))
			return false;

		return ($this->transaction_id = $transaction_id);
	}

	public function getTransactionId()
	{
		return $this->transaction_id;
	}

	public function getBankURL()
	{
		return $this->bank_url;
	}

	public function getPaymentURL()
	{
		return (string) $this->payment_url;
	}

	public function getPaidStatus()
	{
		return $this->paid_status;
	}

	public function getBankStatus()
	{
		return $this->status;
	}

	public function getConsumerInfo()
	{
		return $this->consumer_info;
	}

	public function getErrorMessage()
	{
		return $this->error_message;
	}

	public function getErrorCode()
	{
		return $this->error_code;
	}

}
