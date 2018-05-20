<?php

class OpsWay_Warehouses_Adminhtml_AjaxController extends Mage_Adminhtml_Controller_Action
{
    public function getWarehousesForOrderHtmlAction()
    {
        $orderId = $this->getRequest()->getParam('order_id');
        echo Mage::getModel('opsway_warehouses/resource_ajax_warehouse')->getWarehouseHtml($orderId);
        exit;
    }

    public function getWarehousesForOrderIdsHtmlAction()
    {
        $ids = $this->getRequest()->getParam('ids');
        echo Mage::getModel('opsway_warehouses/resource_ajax_warehouse')->getWarehouseHtmlByIds($ids);
        exit;
    }

    public function sendDropShippingEmailForOrderAction()
    {
        $orderId = $this->getRequest()->getParam('order_id');

        $order = Mage::getModel('sales/order')->load($orderId);
        $storeId = Mage::app()->getStore()->getId();

        $email = $_GET['email'];
        if (!Zend_Validate::is($email, 'EmailAddress')) {
            echo false;
            die();
        }
        $templateId = 50;
        $mailer = Mage::getModel('core/email_template_mailer');
        $emailInfo = Mage::getModel('core/email_info');
        $emailInfo->addTo($email, (string) $email);
        $mailer->addEmailInfo($emailInfo);
        $mailer->setSender(array('email'=> Mage::getStoreConfig('trans_email/ident_custom1/email'), 'name'=> Mage::getStoreConfig('trans_email/ident_custom1/name')));
        $mailer->setReplyTo(Mage::getSingleton('admin/session')->getUser()->getEmail());
        $mailer->setStoreId($storeId);
        $mailer->setTemplateId($templateId);
        $mailer->setTemplateParams(array(
                'order' => $order,
                'billing' => $order->getBillingAddress(),
                'customer_name' => $email,
                'customer_address' => $order->getCustomerAddress(),
                'shipping_address' => $order->getBillingAddress()->format('html'),
            )
        );
        try {
            $mailer->send();
            echo true;
        } catch (Mage_Core_Exception $e) {
            var_dump($e->getMessage());
        }
    }

    public function createDropShippingForOrderIdAction()
    {
        $dropshipFlag = false;
        $dropshipOrdersArray = array();
        $orderId = $this->getRequest()->getParam('order_id');

        if ($orderId) {
            $order = Mage::getModel('sales/order')->load($orderId);
            foreach ($order->getAllVisibleItems() as $_product) {
                $product = Mage::getModel('catalog/product')->loadByAttribute('sku', $_product->getSku());
                $productDropshipCode = $product->getData('drop_seller_code');
                if ($productDropshipCode !='АНТ' && $productDropshipCode !='') {
                    $dropshipOrdersArray[$product->getData('drop_seller_code')][] = $_product->getId();
                } else {
                    $dropshipOrdersArray['АНТ'][] = $_product->getId();
                }
            }

            if (count($dropshipOrdersArray) >= 2) {
                $dropshipFlag = true;
            }
            $i = 1;
            if ($dropshipFlag) {
                foreach ($dropshipOrdersArray as $item) {
                    Mage::unregister('rule_data');
                    Mage::getSingleton('adminhtml/session_quote')->clear();

                    $newQuote = new Mage_Sales_Model_Quote();
                    $newQuote->setStoreId($order->getStoreId());
                    Mage::getSingleton('adminhtml/sales_order_create')->setQuote($newQuote);
                    $order_model = Mage::getSingleton('adminhtml/sales_order_create');
                    $order->setReordered(true);
                    Mage::getSingleton('adminhtml/session_quote')->setUseOldShippingMethod(true);
                    try {
                        $reorder = $order_model->initFromOrder($order, $item);
                        $newOrder = $reorder->createOrder($order, $i);
                    } catch (Mage_Core_Exception $e) {
                        Mage::getSingleton('adminhtml/session')->addError('Order separate dropshipping:'.$e->getMessage());
                        continue;
                    }
                    if ($order->getStatus() !== 'canceled') {
                        $order->setStatus("canceled");
                        $order->save();
                    }
                    $reOrderIncId = $newOrder->getIncrementId();
                    $i++;
                    Mage::getSingleton('adminhtml/session')->addSuccess($this->__('The order has been created from '.$order->getIncrementId().'. order_id = '.$reOrderIncId));
                    echo $dropshipFlag;
                }
            } else {
                Mage::getSingleton('adminhtml/session')->addSuccess($this->__('В заказе только один контрагент'));
                echo $dropshipFlag;
            }
        }
        exit;
    }

    public function saveWarehousesViaAjaxAction()
    {
        $orderId = $this->getRequest()->getParam('order_id');
        $orderWarehouse = $this->getRequest()->getParam('order_warehouse');
        $itemWarehouses = $this->getRequest()->getParam('item_warehouses');

        $order = Mage::getModel('sales/order')->load($orderId);
        $order->setWarehouseStockId($orderWarehouse);
        $order->setWarehouseStockWasSet(1);
        $order->save();

        $itemWarehousesArray = json_decode($itemWarehouses);
        foreach ($itemWarehousesArray as $itemWarehouse) {
            $itemWarehouseExploded = $itemWarehouse;
            $item = Mage::getModel('sales/order_item')->load($itemWarehouseExploded[0]);
            if (!$itemWarehouseExploded[1] && $orderWarehouse) {
                $item->setWarehouseStockId($orderWarehouse);
            } else {
                $item->setWarehouseStockId($itemWarehouseExploded[1]);
            }

            if (isset($itemWarehouseExploded[2]) && $itemWarehouseExploded[2]) {
                $item->setWarehouseStockComment($itemWarehouseExploded[2]);
            }

            $item->save();
        }
        exit;
    }

    public function sendDocumentsToWarehouseAction()
    {
        $whSender = null;
		$whRecipient = null;
		$deliveryType = 'Внутренняя логистика';
		$whEmailRecipient = null;
		
        $orderId = $this->getRequest()->getParam('order_id');
        $order = Mage::getModel('sales/order')->load($orderId);
        $template = "documents2warehouse_pickup";
        $whEmail = Mage::getModel('opsway_warehouses/warehouse')->load($order->getWarehouseStockId())->getEmail();
		$cargo = json_decode($order->getCargoData());
			
		if($order->isTransferWarehouse()){
	        $template = "perebroska_template";	
			$whSender = $cargo->local_warehouse;
			$whRecipient = $order->getTransferWarehouse();
			$whEmailRecipient = Mage::getModel('opsway_warehouses/warehouse')->load($whRecipient, 'code')->getEmail();
			
			if ($order->getData('shipping_operator') == 'Новая Почта'){
				$deliveryType = 'Новая Почта';
			}			
		}
							
        $attachments = [
            'shipment' => Mage::getModel('sales/order_pdf_shipment')->getPdf($order)->render(),
        ];

        $variables = [
            'order' => $order,
            'order_items_grid' => $this->getOrderItemsTable($order),
            'recipient_name' => $order->getShippingAddress()->getFirstname() . " " . $order->getShippingAddress()->getLastname(),
            'recipient_address' => $order->getShippingAddress()->getFormated(),
            'created_at' => $order->getCreatedAt(),
			'wh_sender' => $whSender,
	//		'wh_recipient' => $whRecipient,
	//		'wh_delivery_type' =>  $deliveryType,
        ];

        $storeId = Mage::app()->getStore('antoshka_ru')->getId();
        $mailer = Mage::getModel('core/email_template_mailer');
        $emailInfo = Mage::getModel('core/email_info');
        $emailInfo->addTo($whEmail, $whEmail);
		
		if ($order->isTransferWarehouse()){
			$emailInfo2 = Mage::getModel('core/email_info');
			$emailInfo2->addTo($whEmailRecipient, $whEmailRecipient);
			$emailInfo2->addBcc('e-comm@redhead.ua', 'e-comm@redhead.ua');
			$mailer->addEmailInfo($emailInfo2);
		}
        $mailer->addEmailInfo($emailInfo);
        $mailer->setSender(array('email'=> Mage::getStoreConfig('trans_email/ident_custom1/email'), 'name'=> Mage::getStoreConfig('trans_email/ident_custom1/name')));
        $mailer->setReplyTo(Mage::getSingleton('admin/session')->getUser()->getEmail());
        $mailer->setStoreId($storeId);

        if ($order->getShippingMethod() == 'freeshipping_freeshipping' || $order->getShippingMethod() == 'flatrate_flatrate') {
			
            if(!$order->isTransferWarehouse()){
				$template = 'documents2warehouse_novaposhta';
			}

            //$cargo = json_decode($order->getCargoData());
			
            if (!property_exists($cargo, 'operator_doc_num') || !$cargo->operator_doc_num) {
                "Express Invoice didn't created. Create it before send documents";
                exit;
            }

            $npMarkingUrl = Mage::getModel('opsway_novaposhta/api_client')
                ->getUrl('orders', 'printMarkings', array('orders' => $cargo->operator_doc_num, 'type' => 'pdf'));
            $npDocumentUrl = Mage::getModel('opsway_novaposhta/api_client')
                ->getUrl('orders', 'printDocument', array('orders[]' => $cargo->operator_doc_num, 'type' => 'pdf'));
            $attachments['np_marking'] = file_get_contents($npMarkingUrl);
            $attachments['np_document'] = file_get_contents($npDocumentUrl);
			
        }		
	
        $mailer->setTemplateId(Mage::getModel('core/email_template')->loadByCode($template)->getId());
		
        foreach ($attachments as $name => $content) {
            $mailer->createAttachment(
                $content,
                'application/pdf',
                Zend_Mime::DISPOSITION_ATTACHMENT,
                Zend_Mime::ENCODING_BASE64,
                $name . '.pdf'
            );
        }	
		
        $mailer->createAttachment(
            file_get_contents(Mage::getBaseDir('media').'/xrupko.jpg'),
            'image/jpeg',
            Zend_Mime::DISPOSITION_ATTACHMENT,
            Zend_Mime::ENCODING_BASE64,
            'sticker.jpg'
        );
        $mailer->setTemplateParams($variables);
        $mailer->send();
        $comment = "E-mail с документами отправлен на склад. Статус отправки: ";
        $comment .= ($mailer->getSentSuccess()) ? "ОТПРАВЛЕНО" : "НЕ ОТПРАВЛЕНО";
        $order->addStatusHistoryComment($comment);
        $order->save();
    }

    protected function getOrderItemsTable($order)
    {
        $html = "<table>";
        $html .= "<tr><th>Код товара</th><th>ТМ</th><th>Артикул</th><th>Товар</th><th>Цена</th><th>Заказ (кол-во)</th><th>Сумма по заказу</th></tr>";
        foreach ($order->getAllVisibleItems() as $orderItem) {
            $item = Mage::getModel('catalog/product')->load($orderItem->getProductId());
            $html .= "<tr>";
            $html .= "<td>" . $item->getSku() . "</td>";
            $html .= "<td>" . $item->getAttributeText('tm_id') . "</td>";
            $html .= "<td>" . $item->getItemArticle() . "</td>";
            $html .= "<td>" . $item->getName() . "</td>";
            $html .= "<td>" . $item->getFinalPrice() . "</td>";
            $html .= "<td>" . $orderItem->getQtyOrdered() . "</td>";
            $html .= "<td>" . $orderItem->getRowTotal() . "</td>";
            $html .= "</tr>";
        }
        $html .= "</table>";

        return $html;
    }

    public function queryWarehousesAction()
    {
        $orderId = $this->getRequest()->getParam('order_id');
        $order = Mage::getModel('sales/order')->load($orderId);
        echo Mage::helper('opsway_warehouses')->sendQuery($order);
    }

    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('sales/order');
    }
}
