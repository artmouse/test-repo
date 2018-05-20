<?php

class Adminhtml_AutoSendmail_Adminhtml_AutosendController extends Mage_Adminhtml_Controller_Action
{
    const role_action_access = array('Administrators', 'Продвинутый менеджер', 'Development', 'Отдел продаж - модератор');
    const role_status_null = 'Отдел продаж';
    const role_status_logistic = 'Отдел логистики';

    protected function _isAllowed()
    {
        return true;
    }

    public function indexAction()
    {
        try {
            $is_processing = null;
            $admin_user_session = Mage::getSingleton('admin/session');
            if (!$admin_user_session->getUser()) {
                return false;
            }

            $adminuserId = $admin_user_session->getUser()->getUserId();

            $admin_data = Mage::getModel('admin/user')->load($adminuserId);
            $manager = $admin_data->getData('lastname') . ' ' . $admin_data->getData('firstname');

            $role_data = $admin_data->getRole()->getData();
            if (self::role_status_logistic == $role_data['role_name']) {
                $is_processing = 2;
            }

            if (self::role_status_null == $role_data['role_name']) {
                $is_processing = 1;
            }

            $contItemOrder = trim(Mage::getStoreConfig('adminhtml_autosendmail/settings_exception/filter_by_count'));

            $orderIds = $this->getRequest()->getPost('order_ids', array());
            foreach ($orderIds as $orderId) {
                $order = Mage::getModel('sales/order')->load($orderId);

                if ($contItemOrder == '') {
                    continue;
                }

                if ($order->getData('total_item_count') > $contItemOrder) {
                    echo 'В заказе <b>' . $order->getData('increment_id') . '</b> товаров больше чем установленно в настройках.<br>';
                    continue;
                }


                $array = Mage::helper('adminhtml_autosendmail/data')->getWarhouse($order);

                $status = Mage::helper('adminhtml_autosendmail/data')->sendQuery($order, $array);

                $string = ' <b>' . $order->getData('increment_id') . '</b> - ';
                foreach ($array as $item) {
                    foreach ($item as $key=>$value) {
                        $string .= ' Для товара <b>' . $value['item_sku'] . '</b> склад ';
                        $string .= $value['wh_name'];

                        if (array_key_exists($value['wh_name'], $status)) {
                            if ($status[$value['wh_name']]) {
                                $order->setManager($manager);
                                if ($is_processing !== null) {
                                    $order->setData('is_processing', $is_processing);
                                    $order->save();
                                }
                                $string .= '<b> Отправлено;</b>';
                            } else {
                                $string .= '<b> Не отправлено;</b>';
                            }
                        } else {
                            $string .= '<b> Не отправлено;</b>';
                        }
                    }
                }
                $string .= '<br>';

                echo $string;
            }
        } catch (Exception $ex) {
            var_dump($ex);
            echo 'При выполнении действия возникли ошибки... <br />';
        }
        echo '<a href="'. Mage::helper("adminhtml")->getUrl("*/sales_order/index") .'">Перейти к списку заказов</a>';
        die();
    }
}
