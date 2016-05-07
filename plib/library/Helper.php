<?php

class Modules_VirustotalSiteChecker_Helper
{
    const virustotal_scan_url = 'https://www.virustotal.com/vtapi/v2/url/scan';
    const virustotal_report_url = 'https://www.virustotal.com/vtapi/v2/url/report';
    const virustotal_domain_info_url = 'https://www.virustotal.com/domain/%s/information/';
    const virustotal_api_timeout = 20;
    const virustotal_api_day_limit = 5;

    public static  function check()
    {
        
        
        if (!pm_Settings::get('virustotal_enabled') || !pm_Settings::get('virustotal_api_key')) {
            return;
        }

        self::report();
        
        pm_Settings::set('total_domains_checked', 0);
        foreach (self::getDomains() as $domain) {
            if (!self::is_last_domain('check', $domain)) {
                continue;
            }
            
            $report = json_decode(pm_Settings::get('domain_id_' . $domain->id), true);
            if ($report && !$report['virustotal_request_done']) {
                continue;
            }
            if (!$report) {
                $report = [];
            }
            /*
            if (!$domain->isValid()) {
                continue;
            }
            */
            if (self::is_enough()) {
                exit(0);
            }
            $report['domain'] = $domain;
            $report['virustotal_request_done'] = false;
            $request = self::virustotal_scan_url_request($domain->ascii_name);
            $report['virustotal_request'] = array(
                'response_code' => $request['response_code'],
                'scan_date' => $request['scan_date'],
                'scan_id' => $request['scan_id'],
            );


            pm_Settings::set('domain_id_' . $domain->id, json_encode($report));
            pm_Settings::set('last_scan', date("d/M/Y G:i"));
            pm_Settings::set('total_domains_checked', pm_Settings::get('total_domains_checked') + 1);
        }

        self::cleanup_last_domains();
    }

    /**
     * VirusTotal API has restriction in 4 req/min, for safety we have limit to 3 req/min (4320 req/day)
     * 
     * @return bool
     */
    public static function is_enough()
    {
        static $counter = 0;
        if ($counter >= self::virustotal_api_day_limit) {
            return true;
        }
        $counter++;
        return false;
    }

    /**
     * @param  $operation string
     * @param  $domain Modules_VirustotalSiteChecker_PleskDomain
     * @return bool
     */
    public static function is_last_domain($operation, $domain)
    {
        $last = json_decode(pm_Settings::get('last_domain_' . $operation), true);
        if (!$last) {
            pm_Settings::set('last_domain_' . $operation, json_encode($domain));
            return true;
        }

        if ($domain->id < $last['id']) {
            return false;
        }

        pm_Settings::set('last_domain_' . $operation, json_encode($domain));
        return true;
    }

    public static function report()
    {
        foreach (self::getDomains() as $domain) {
            if (!self::is_last_domain('report', $domain)) {
                continue;
            }
            $request = json_decode(pm_Settings::get('domain_id_' . $domain->id), true);
            if (!$request) {
                continue;
            }
            if (self::is_enough()) {
                exit(0);
            }
            $report = self::virustotal_scan_url_report($domain->ascii_name);
            //error_log(print_r($report, 1));
            if (isset($report['positives'])) {
                $request['virustotal_request_done'] = true;
                $request['virustotal_report_positives'] = $report['positives'];
                pm_Settings::set('domain_id_' . $domain->id, json_encode($request));

                if ($report['positives'] > 0) {
                    self::report_domain($domain, $report);
                } else {
                    self::unreport_domain($domain);
                }
            }
        }
    }

    public static function cleanup_last_domains()
    {
        $ops = ['report', 'check'];
        foreach ($ops as $operation) {
            pm_Settings::set('last_domain_' . $operation, false);
        }
    }

    /**
     * @param $domain Modules_VirustotalSiteChecker_PleskDomain
     * @return null
     */
    public static function unreport_domain($domain)
    {
        $report = json_decode(pm_Settings::get('domain_id_' . $domain->id), true);
        if (!$report) {
            return;
        }
        unset($report['virustotal_domain_info_url']);
        unset($report['virustotal_positives']);
        unset($report['virustotal_total']);
        unset($report['virustotal_scan_date']);

        pm_Settings::set('domain_id_' . $domain->id, json_encode($report));
    }

    /**
     * @param $domain Modules_VirustotalSiteChecker_PleskDomain
     * @param $new_report array
     * @return null
     */
    public static function report_domain($domain, $new_report)
    {
        $report = json_decode(pm_Settings::get('domain_id_' . $domain->id), true);
        if (!$report) {
            $report = [];
        }

        $report['domain'] = $domain;
        $report['virustotal_domain_info_url'] = sprintf(self::virustotal_domain_info_url, $domain->ascii_name);
        $report['virustotal_positives'] = $new_report['positives'];
        $report['virustotal_total'] = isset($new_report['total']) ? $new_report['total'] : '';
        $report['virustotal_scan_date'] = isset($new_report['scan_date']) ? $new_report['total'] : '';

        pm_Settings::set('domain_id_' . $domain->id, json_encode($report));
    }

    /**
     * @param $url string
     * @return array
     */
    public static function virustotal_scan_url_request($url)
    {
        $client = new Zend_Http_Client(self::virustotal_scan_url);

        $client->setParameterPost('url', $url);
        $client->setParameterPost('apikey', pm_Settings::get('virustotal_api_key'));
        sleep(self::virustotal_api_timeout);
        $response = $client->request(Zend_Http_Client::POST);

        return json_decode($response->getBody(), true);
    }

    /**
     * @param $url string
     * @return array
     */
    public static function virustotal_scan_url_report($url)
    {
        $client = new Zend_Http_Client(self::virustotal_report_url);

        $client->setParameterPost('resource', $url);
        $client->setParameterPost('apikey', pm_Settings::get('virustotal_api_key'));
        sleep(self::virustotal_api_timeout);
        $response = $client->request(Zend_Http_Client::POST);

        return json_decode($response->getBody(), true);
    }

    /**
     * @return array[string]
     *              ['bad']     Modules_VirustotalSiteChecker_PleskDomain[]
     *              ['total']   int
     */
    public static function getDomainsReport()
    {
        static $domains = [
            'bad' => [],
            'total' => 0,
        ];
        if ($domains['total'] > 0) {
            return $domains;
        }
        foreach (self::getDomains() as $domain) {
            $report = json_decode(pm_Settings::get('domain_id_' . $domain->id), true);
            if (!$report) {
                continue;
            }

            $domains['total']++;

            if (!isset($report['virustotal_positives']) || $report['virustotal_positives'] <= 0) {
                continue;
            }

            $domain->virustotal_positives = $report['virustotal_positives'];
            $domain->virustotal_total = $report['virustotal_total'];
            $domain->virustotal_domain_info_url = $report['virustotal_domain_info_url'];

            $domains['bad'][$domain->id] = $domain;

        }
        
        return $domains;
    }

    /**
     * @return Modules_VirustotalSiteChecker_PleskDomain[]
     */
    public static function getDomains()
    {
        static $domains = [];
        if ($domains) {
            return $domains;
        }
        $sites_request = '<site><get><filter/><dataset><gen_info/></dataset></get></site>';
        $websp_request = '<webspace><get><filter/><dataset><gen_info/></dataset></get></webspace>';
        $api = pm_ApiRpc::getService();
        // site->get->result->[ id, data -> gen_info ( [cr_date] , [name] , [ascii-name] , [status] => 0 , [dns_ip_address] , [htype] )
        $sites_response = $api->call($sites_request);
        $websp_response = $api->call($websp_request);

        $sites = json_decode(json_encode($sites_response->site->get));
        $websp = json_decode(json_encode($websp_response->webspace->get));

        $sites_array =  is_array($sites->result) ? $sites->result : array($sites->result);
        $websp_array =  is_array($websp->result) ? $websp->result : array($websp->result);

        $tmp_list = array_merge($sites_array, $websp_array);
        foreach ($tmp_list as $domain) {

            $domains[$domain->id] = new Modules_VirustotalSiteChecker_PleskDomain(
                $domain->id,
                $domain->data->gen_info->name,
                $domain->data->gen_info->{'ascii-name'},
                $domain->data->gen_info->status,
                is_array($domain->data->gen_info->dns_ip_address) ? $domain->data->gen_info->dns_ip_address : array($domain->data->gen_info->dns_ip_address),
                $domain->data->gen_info->htype,
                isset($domain->data->gen_info->{'webspace-id'}) ? $domain->data->gen_info->{'webspace-id'} : $domain->id
            );
        }

        ksort($domains);
        return $domains;
    }
}