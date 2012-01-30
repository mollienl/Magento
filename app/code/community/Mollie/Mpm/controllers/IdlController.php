<?php

/**
 * Copyright (c) 2012, Mollie B.V.
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
 * @category    Mollie
 * @package     Mollie_Mpm
 * @author      Mollie B.V. (info@mollie.nl)
 * @version     v2.0.0
 * @copyright   Copyright (c) 2012 Mollie B.V. (http://www.mollie.nl)
 * @license     http://www.opensource.org/licenses/bsd-license.php  Berkeley Software Distribution License (BSD-License 2)
 * 
 **/

class Mollie_Mpm_IdlController extends Mage_Core_Controller_Front_Action
{

	// Initialize vars
	protected $_read;
	protected $_write;
	protected $_ideal;

	/**
	 * Get iDEAL core
	 * Give $_write mage writing resource
	 * Give $_read mage reading resource
	 */
	public function _construct()
	{
		$this->_ideal = Mage::Helper('mpm/idl');
		$this->_read = Mage::getSingleton('core/resource')->getConnection('core_read');
		$this->_write = Mage::getSingleton('core/resource')->getConnection('core_write');
		parent::_construct();
	}

	/**
	 * Gets the current checkout session with order information
	 * 
	 * @return array
	 */
	protected function _getCheckout()
	{
		return Mage::getSingleton('checkout/session');
	}

	/**
	 * After clicking 'Place Order' the method 'getOrderPlaceRedirectUrl()' gets called and redirects to here with the bank_id
	 * Then this action creates an payment with a transaction_id that gets inserted in the database (mollie_payments, sales_payment_transaction)
	 */
	public function paymentAction()
	{
		// Load last order
		$order = Mage::getModel('sales/order')->loadByIncrementId($this->_getCheckout()->last_real_order_id);

		try
		{
			// Assign required value's
			$bank_id = Mage::app()->getRequest()->getParam('bank_id');
			$amount = intval(number_format($order->getGrandTotal(), 0, '', '') * 100);
			$description =  str_replace('%', $order->getIncrementId(), Mage::Helper('mpm/data')->getConfig('idl', 'description'));
			$return_url = Mage::getUrl('mpm/idl/return');
			$report_url = Mage::getUrl('mpm/idl/report');

			if ($this->_ideal->createPayment($bank_id, $amount, $description, $return_url, $report_url))
			{
				if (!$order->getId())
				{
					Mage::log('Geen order voor verwerking gevonden');
					Mage::throwException('Geen order voor verwerking gevonden');
				}

				// Store order and iDEAL information
				$data = array('order_id' => $order->getIncrementId(),
					'entity_id' => $order->getData('entity_id'),
					'quote_id' => $order->getData('quote_id'),
					'trans_id' => $this->_ideal->getTransactionId(),
				);

				$sql = sprintf("INSERT INTO `%s` (`order_id`, `entity_id`, `method`, `transaction_id`) VALUES ('%s', '%s', '%s', '%s');",
								Mage::getSingleton('core/resource')->getTableName('mollie_payments'),
								$data['order_id'],
								$data['entity_id'],
								'idl',
								$data['trans_id']
							);

				// Writes the above query into the mollie_payments table and then creates a transaction
				if ($this->_write->query($sql))
				{
					// Creates transaction
					$payment = Mage::getModel('sales/order_payment')
							->setMethod('iDEAL')
							->setTransactionId($data['trans_id'])
							->setIsTransactionClosed(false);

					// Sets the above transaction
					$order->setPayment($payment);

					$payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH);

					$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, Mage::helper('mpm')->__(Mollie_Mpm_Model_Idl::PAYMENT_FLAG_INPROGRESS), false)->save();

					$this->_redirectUrl($this->_ideal->getBankURL());
				}
			}
		}
		catch (Exception $e)
		{
			Mage::log($e);
			Mage::throwException(
				"Kon geen betaling aanmaken, neem contact op met de beheerder.<br />
				Error melding voor de beheerder: " . $this->_ideal->getErrorMessage()
			);
		}
	}

	/**
	 * This action is getting called by Mollie to report the payment status
	 */
	public function reportAction()
	{
		// Get transaction_id from url (Ex: http://yourmagento.com/index.php/idl/report?bank_id=9999 )
		$transactionId = Mage::app()->getRequest()->getParam('transaction_id');

		// Get order by transaction_id
		$oId = Mage::helper('mpm/data')->getOrderById($transactionId);

		// Load order by id ($oId)
		$order = Mage::getModel('sales/order')->loadByIncrementId($oId['order_id']);

		try
		{
			if (!empty($transactionId) && $order->getData('status') == Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
			{
				$this->_ideal->checkPayment($transactionId);

				$customer = $this->_ideal->getConsumerInfo();

				$this->_write->query(
								sprintf(
									"UPDATE `%s` SET `bank_status` = '%s', `bank_account` = '%s' WHERE `transaction_id` = '%s' AND `bank_status` = ''",
									Mage::getSingleton('core/resource')->getTableName('mollie_payments'),
									$this->_ideal->getBankStatus(),
									$customer['consumerAccount'],
									$transactionId
								)
							);

				// Creates transaction
				$payment = Mage::getModel('sales/order_payment')
						->setMethod('iDEAL')
						->setTransactionId($transactionId)
						->setIsTransactionClosed(true);

				// Sets the above transaction
				$order->setPayment($payment);

				// Get iDEAL payment status and set it
				if ($this->_ideal->getPaidStatus() == true)
				{
					if ($this->_ideal->getAmount() == intval(number_format($order->getGrandTotal(), 0, '', '') * 100))
					{
						$payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE);
						$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Sales_Model_Order::STATE_PROCESSING, Mage::helper('mpm')->__(Mollie_Mpm_Model_Idl::PAYMENT_FLAG_PROCESSED), true)->save();
					}
					else
					{
						$order->setState(Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage_Sales_Model_Order::STATUS_FRAUD, Mage::helper('mpm')->__(Mollie_Mpm_Model_Idl::PAYMENT_FLAG_FRAUD), false)->save();
					}
				}
				else
				{
					switch ($this->_ideal->getBankStatus())
					{
						case "Cancelled":
							$order->setState(Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage::helper('mpm')->__(Mollie_Mpm_Model_Idl::PAYMENTFLAG_CANCELD), true)->save();
							break;
						case "Failure":
							$order->setState(Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage::helper('mpm')->__(Mollie_Mpm_Model_Idl::PAYMENTFLAG_FAILED), true)->save();
							break;
						case "Expired":
							$order->setState(Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage::helper('mpm')->__(Mollie_Mpm_Model_Idl::PAYMENTFLAG_EXPIRED), true)->save();
							break;
						case "CheckedBefore":
							$order->setState(Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage::helper('mpm')->__(Mollie_Mpm_Model_Idl::PAYMENT_FLAG_DCHECKED), false)->save();
							break;
						default:
							$order->setState(Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, Mage::helper('mpm')->__(Mollie_Mpm_Model_Idl::PAYMENT_FLAG_UNKOWN), true)->save();
							break;
					}
				}

				// Sends email to customer.
				$order->sendNewOrderEmail()->setEmailSent(true)->save();
			}
		}
		catch (Exception $e)
		{
			Mage::log($e);
			Mage::throwException($e);
		}
	}

	/**
	 * Customer returning from the bank with an transaction_id
	 * Depending on what the state of the payment is they get redirected to the corresponding page
	 */
	public function returnAction()
	{
		// Get transaction_id from url (Ex: http://youmagento.com/index.php/idl/return?transaction_id=45r6tuyhijg67u3gds )
		$transactionId = Mage::app()->getRequest()->getParam('transaction_id');

		try
		{
			if (!empty($transactionId))
			{
				// Get payment status from database ( `mollie_payments` )
				$oStatus = Mage::helper('mpm/data')->getStatusById($transactionId);

				if ($oStatus['bank_status'] == "Success")
				{
					// Redirect to success page
					$this->_redirect('checkout/onepage/success', array('_secure' => true));
				}
				else
				{
					// Redirect to fail page
					$this->_redirect('checkout/onepage/failure', array('_secure' => true));
				}
			}
		}
		catch (Exception $e)
		{
			Mage::log($e);
			$this->_redirectUrl(Mage::getBaseUrl());
		}
	}

}
