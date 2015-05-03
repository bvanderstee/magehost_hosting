<?php

class JeroenVermeulen_Hosting_Model_Observer
{
    const CONFIG_SECTION  = 'jeroenvermeulen_hosting';

    /**
     * @param Varien_Event_Observer $observer
     */
    public function cleanMediaCacheAfter( $observer ) {
        $miniDir = Mage::getBaseDir('base') . DIRECTORY_SEPARATOR . 'mini';
        if ( is_dir($miniDir) ) {
            $success = $this->clean_dir_content( $miniDir );
            if ( $success ) {
                Mage::getSingleton('adminhtml/session')->addSuccess( sprintf("Directory '%s' has been cleaned.",$miniDir) );
            } else {
                Mage::getSingleton('adminhtml/session')->addError( sprintf("Error cleaning directory '%s'.",$miniDir) );
            }
        }
    }

    /**
     * @param Varien_Event_Observer $observer
     */
    public function jvCleanBackendCache( $observer ) {
        $transport = $observer->getTransport();
        Mage::log( sprintf('Cache Clean:  mode:%s  tags:%s',$transport->getMode(),implode(',',$transport->getTags())) );
        if ( Mage::getStoreConfigFlag(self::CONFIG_SECTION.'/cluster/enable_pass_cache_clean')
             && ! Mage::registry('JeroenVermeulen_cacheClean_via_Api') ) {
            $transport = $observer->getTransport();
            $nodes = Mage::getStoreConfig(self::CONFIG_SECTION.'/cluster/http_nodes');
            $url = Mage::getUrl('api/v2_soap',array('_query'=>'wsdl'));
            $urlData = parse_url($url);
            foreach ( explode("\n",$nodes) as $node) {
                $node = trim($node);
                $nodeSplit = explode(':',$node);
                $scheme = $urlData['scheme'];
                if ( !empty($nodeSplit[1]) ) {
                    if ( 443 == $nodeSplit[1] ) {
                        $scheme = 'https';
                    }
                    elseif ( 80 == $nodeSplit[1] ) {
                        $scheme = 'http';
                    }
                }
                $nodeWdsl = $scheme."://".$node.$urlData['path'].'?'.$urlData['query'];
                $nodeLocation = $scheme."://".$node.$urlData['path'];
                try {
                    $client = new Zend_Soap_Client();
                    $client->setUri($nodeWdsl);
                    $client->setLocation($nodeLocation);
                    $client->setStreamContext(
                        stream_context_create( array(
                                'ssl' => array( 'verify_peer' => false,
                                                'allow_self_signed' => true ),
                                'http' => array( 'header' => 'Host: '.$urlData['host'] )
                            )
                        )
                    );
                    $sessionId =  $client->login( 'soapuser', 'soappass' ); // TODO
                    $client->jvHostingCacheClean( $sessionId, $transport->getMode(), $transport->getTags() );
                } catch ( Exception $e ) {
                    Mage::log( sprintf("%s::%s: ERROR %s", __CLASS__, __FUNCTION__, $e->getMessage()) );
                }
            }
        }
    }

    /**
     * Does not delete the dir itself, only its contents, recursive.
     * @param string $dir
     * @return bool
     * @throws Exception
     */
    protected function clean_dir_content( $dir ) {
        if ( !is_dir($dir) ) {
            return false;
        }
        $result = true;
        foreach (scandir($dir) as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if ( is_dir($path) ) {
                $this->clean_dir_content( $path );
                $result = $result && rmdir( $path );
            } else {
                $result = $result && unlink( $path );
            }
        }
        return $result;
    }

}