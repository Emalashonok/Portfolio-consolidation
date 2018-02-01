<?php
include_once('symfony.inc.php');

$envir = array(
    "url" => "https://ndrs-st002.openprovider.nl",
    "env from" => "test2",
    "env to" => "test",
    "input file" => "listLive.csv",
    "output file" => "Cost-wat.csv",
    "stage file" => "stage.txt"
);
$envirLive['env from'] = sfConfig::get('app_system_domreg_env');
$envirLive['url'] = sfConfig::get('app_domain_ndrs_url');

$helper = Helper;
$reportMass = $dNameMass;
$domainRRP = DomainRRP;
$domainArnes = Domain;
$domainAPI = DomainAPI;

$dNameMass =$helper::readFromScv($envir['input file'], ['domainName','authCode']);//,'stage','status']);

foreach ($dNameMass as $key => $fields) {
     $domain = opDomainPeer::searchOne([
                    'fullName' =>$dNameMass[$key]['domainName']
                   ]
                );
    
    $dNameMass[$key]['ResellerId'] = $domain->getResellerId();
    $dNameMass[$key]['Vat percent'] = $vat = $domain->getReseller()->getVatPercentage();
    $dNameMass[$key]['Arnes price'] = opBillableObjManager::getPrice($domain, ['type' => 'renew'])['price']/($vat+100)*100;
    $domain->setRouteId(15);
    $dNameMass[$key]['rrp price'] = opBillableObjManager::getPrice($domain, ['type' => 'renew'])['price']/($vat+100)*100;
    $dNameMass[$key]['diff'] =  $dNameMass[$key]['rrp price'] - $dNameMass[$key]['Arnes price'];

print_r("\n$key ".$dNameMass[$key]['domainName']);

}
$helper::writeToScv($envir['output file'], $dNameMass);
print_r($dNameMass);

class Helper
{

    public static function compareString($response, $flag)
    {
         $regexp = '/'.$flag.'/si';
         return preg_match($regexp, $response);
    }
    
    public static function parseMsgRegular ($response, $tag)
    {
       $regexpMSG = "/<".$tag."[^>]*>(.*?)<\\/".$tag."/si";
       $match =[];
       preg_match($regexpMSG, $response, $match);

       return $match[1]; //returns <$tag>***this***</$tag> 
    }

    public static function readStage($inputfile)
    {
        if (($fp = fopen($inputfile, "r")) !== FALSE) {
            return(fread($fp, 100));
        }
        fclose($fp);
    }    

    public static function writeStage($inputfile, $stage)
    {
        $fp = fopen($inputfile, "w");
        fwrite($fp, $stage);
        fclose($fp);
    }    

    public static function readFromScv($inputfile, $arrKeys = [])
    {
        if (($fp = fopen($inputfile, "r")) !== FALSE) {
            while (($data = fgetcsv($fp, 0, ";")) !== FALSE) {
                $dNameMass[] = empty($arrKeys) ? $data : array_combine($arrKeys, $data);
            }   
            fclose($fp);
        }
       
        return $dNameMass;
    }

    public static function writeToScv($outputfile, $dNameMass)
    {
        $fp = fopen($outputfile, 'w');
        foreach ($dNameMass as $fields) {
            fputcsv($fp, $fields, ';', '"');
        }   
        fclose($fp);
    }
}
