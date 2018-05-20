<?php

set_include_path('/var/www/releases/20170302092950Z/public/');
require_once('app/Mage.php');Mage::init();

$shops = Mage::getModel('onibi_storelocator/store')->getCollection();
$choicesArray = array();
       
foreach ($shops as $shop) {
            
$label = Mage::helper("antoshka_extended")->getShopLabel($shop);
            $choicesArray[$label] = array(
                'value' => $shop->getId(),
                'label' => $label
            );
}
ksort($choicesArray);
echo json_encode($choicesArray);

