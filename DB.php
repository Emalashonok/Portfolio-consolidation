<?php
    include_once('symfony.inc.php');
    $outputfile = 'list_RRP.scv';
    $domainObjects = opDomainPeer::search(array(
        'routeId' => 15,
        'status' => 'ACT',
        'extension' => 'si',
        'limit' => 200
    ));
        $domainName=[];
        $fp = fopen($outputfile, 'w');
        foreach($domainObjects as $key => $domainObj) {
        $domainName[$key] =$domainObj->getFullName();
        $extension = $domainObj->getExtensionName();
//        fputcsv($fp, $domaiName[$key], ';', '"');
        
     //   print_r($domainName.";\n");
  //      print_r($key." : ".$domainName."\n");

}
        sort($domainName);
        print_r($domainName);
        fclose($fp);
?>

