<?php

/**
 * PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 * by https://t.me/ibnux
 *
 * This is Core, don't modification except you want to contribute
 * better create new plugin
 **/

use PEAR2\Net\RouterOS;

class MikrotikHotspot
{

    // show Description
    function description()
    {
        return [
            'title' => 'Mikrotik Hotspot',
            'description' => 'To handle connection between PHPNuxBill with Mikrotik Hotspot',
            'author' => 'ibnux',
            'url' => [
                'Github' => 'https://github.com/hotspotbilling/phpnuxbill/',
                'Telegram' => 'https://t.me/phpnuxbill',
                'Donate' => 'https://paypal.me/ibnux'
            ]
        ];
    }


    function add_customer($customer, $plan)
    {
        global $isChangePlan;

        $mikrotik = $this->info($plan['routers']);

        if (!$mikrotik) {
            throw new Exception(
                'MikroTik router not found: ' . ($plan['routers'] ?? '')
            );
        }

        $client = $this->getClient(
            $mikrotik['ip_address'],
            $mikrotik['username'],
            $mikrotik['password']
        );

        if (!$client) {
            return;
        }

        // IMPORTANT:
        // This function ONLY manages HOTSPOT accounts.
        // PPPoE accounts are managed separately by MikrotikPppoe.
        //
        // A customer can have both Hotspot and PPPoE accounts
        // with the same username.

        // Keep MikroTik profile shared-users aligned with the purchased package.
        $this->ensureHotspotProfileSharedUsers($client, $plan);

        // Check if user already exists in MikroTik Hotspot
        // NOT PPPoE.
        $userId = $this->getHotspotUserId(
            $client,
            $customer['username']
        );

        if (empty($userId)) {

            // Hotspot user doesn't exist.
            // Create new hotspot account.
            $this->addHotspotUser(
                $client,
                $plan,
                $customer
            );

        } else {

            // Hotspot user already exists.
            //
            // IMPORTANT:
            // This updates the existing MikroTik user's password,
            // profile, comment and limits.
            //
            // This is the path used when an expired customer
            // purchases/reconnects again.
            $this->updateHotspotUser(
                $client,
                $userId,
                $plan,
                $customer
            );

            // Only disconnect on an explicit plan change.
            // Do NOT use plan_expired lookups here.
            if (!empty($isChangePlan)) {
                $this->removeHotspotActiveUser(
                    $client,
                    $customer['username']
                );
            }
        }
    }


    function sync_customer($customer, $plan)
    {
        $mikrotik = $this->info($plan['routers']);

        if (!$mikrotik) {
            return;
        }

        $client = $this->getClient(
            $mikrotik['ip_address'],
            $mikrotik['username'],
            $mikrotik['password']
        );

        if (!$client) {
            return;
        }

        $t = ORM::for_table('tbl_user_recharges')
            ->where('username', $customer['username'])
            ->where('routers', $plan['routers'])
            ->where('status', 'on')
            ->order_by_desc('recharged_on')
            ->find_one();

        if ($t) {

            $printRequest = new RouterOS\Request(
                '/ip/hotspot/user/print'
            );

            $printRequest->setArgument(
                '.proplist',
                '.id,limit-uptime,limit-bytes-total'
            );

            $printRequest->setQuery(
                RouterOS\Query::where(
                    'name',
                    $customer['username']
                )
            );

            $userInfo = $client->sendSync($printRequest);

            $id = $userInfo->getProperty('.id');
            $uptime = $userInfo->getProperty('limit-uptime');
            $data = $userInfo->getProperty('limit-bytes-total');

            if (!empty($id)) {

                // User exists - update profile, comment,
                // password, email and limits.

                $expiration_info = $this->getExpirationInfo(
                    $customer['username'],
                    $plan['routers']
                );

                $setRequest = new RouterOS\Request(
                    '/ip/hotspot/user/set'
                );

                $setRequest->setArgument(
                    'numbers',
                    $id
                );

                $setRequest->setArgument(
                    'profile',
                    $t['namebp']
                );

                // IMPORTANT:
                // Always synchronize the password from the billing
                // database to MikroTik.
                $setRequest->setArgument(
                    'password',
                    $customer['password']
                );

                $setRequest->setArgument(
                    'comment',
                    $customer['fullname']
                    . ' | '
                    . implode(
                        ', ',
                        User::getBillNames($customer['id'])
                    )
                    . $expiration_info
                );

                $setRequest->setArgument(
                    'email',
                    $customer['email']
                );

                // Update uptime and data limits based on plan.
                $remainUptime = $this->resolveLimitUptime(
                    $customer,
                    $plan
                );

                if ($remainUptime !== null) {

                    $setRequest->setArgument(
                        'limit-uptime',
                        $remainUptime
                    );

                } elseif ($plan['typebp'] == "Limited") {

                    // Handle Time Limit or Both Limit.
                    if (
                        $plan['limit_type'] == "Time_Limit"
                        ||
                        $plan['limit_type'] == "Both_Limit"
                    ) {

                        if ($plan['time_unit'] == 'Hrs') {
                            $timelimit =
                                $plan['time_limit'] . ":00:00";
                        } else {
                            $timelimit =
                                "00:" . $plan['time_limit'] . ":00";
                        }

                        $setRequest->setArgument(
                            'limit-uptime',
                            $timelimit
                        );

                    } else {

                        // If plan doesn't have time limit
                        // but user has uptime limit, remove it.
                        if (!empty($uptime)) {
                            $setRequest->setArgument(
                                'limit-uptime',
                                ''
                            );
                        }
                    }

                    // Handle Data Limit or Both Limit.
                    if (
                        $plan['limit_type'] == "Data_Limit"
                        ||
                        $plan['limit_type'] == "Both_Limit"
                    ) {

                        if ($plan['data_unit'] == 'GB') {
                            $datalimit =
                                $plan['data_limit'] . "000000000";
                        } else {
                            $datalimit =
                                $plan['data_limit'] . "000000";
                        }

                        $setRequest->setArgument(
                            'limit-bytes-total',
                            $datalimit
                        );

                    } else {

                        // If plan doesn't have data limit
                        // but user has data limit, remove it.
                        if (!empty($data)) {
                            $setRequest->setArgument(
                                'limit-bytes-total',
                                ''
                            );
                        }
                    }

                } else {

                    // Plan is Unlimited.
                    if (
                        $remainUptime === null
                        &&
                        !empty($uptime)
                    ) {
                        // Keep existing uptime.
                    }

                    if (
                        !empty($data)
                        &&
                        $remainUptime === null
                    ) {
                        $setRequest->setArgument(
                            'limit-bytes-total',
                            ''
                        );
                    }
                }

                // Send update to MikroTik.
                $client->sendSync($setRequest);

            } else {

                // User doesn't exist.
                // Create properly.
                $this->add_customer(
                    $customer,
                    $plan
                );
            }
        }
    }


    function remove_customer($customer, $plan)
    {
        $mikrotik = $this->info($plan['routers']);

        if (!$mikrotik) {
            return;
        }

        $client = $this->getClient(
            $mikrotik['ip_address'],
            $mikrotik['username'],
            $mikrotik['password']
        );

        if (!$client) {
            return;
        }

        $username = (string)($customer['username'] ?? '');

        if ($username === '') {
            return;
        }

        // Always force captive portal on expiry:
        //
        // 1. Kill every active session.
        // 2. Clear Hotspot cookies.
        // 3. Remove Hotspot user.
        //
        // This forces the phone back to the captive portal.

        $this->forceCaptivePortal(
            $client,
            $username
        );

        $this->removeHotspotUser(
            $client,
            $username
        );

        // Second pass in case a cookie/session raced the remove.
        $this->forceCaptivePortal(
            $client,
            $username
        );
    }


    /**
     * Kick every active session + clear cookies
     * for a Hotspot username.
     */
    function forceCaptivePortal($client, $username)
    {
        $this->removeHotspotActiveUser(
            $client,
            $username
        );

        $this->removeHotspotCookies(
            $client,
            $username
        );
    }


    // Customer change username.
    public function change_username($plan, $from, $to)
    {
        $mikrotik = $this->info($plan['routers']);

        if (!$mikrotik) {
            return;
        }

        $client = $this->getClient(
            $mikrotik['ip_address'],
            $mikrotik['username'],
            $mikrotik['password']
        );

        if (!$client) {
            return;
        }

        // Check if customer exists.
        $printRequest = new RouterOS\Request(
            '/ip/hotspot/user/print'
        );

        $printRequest->setArgument(
            '.proplist',
            '.id'
        );

        $printRequest->setQuery(
            RouterOS\Query::where(
                'name',
                $from
            )
        );

        $id = $client
            ->sendSync($printRequest)
            ->getProperty('.id');

        // FIX:
        // Original code checked $cid, which was undefined.
        if (!empty($id)) {

            $setRequest = new RouterOS\Request(
                '/ip/hotspot/user/set'
            );

            $setRequest->setArgument(
                'numbers',
                $id
            );

            $setRequest->setArgument(
                'name',
                $to
            );

            $client->sendSync($setRequest);

            // Disconnect old username.
            $this->removeHotspotActiveUser(
                $client,
                $from
            );
        }
    }


    function add_plan($plan)
    {
        $mikrotik = $this->info($plan['routers']);

        if (!$mikrotik) {
            return;
        }

        $client = $this->getClient(
            $mikrotik['ip_address'],
            $mikrotik['username'],
            $mikrotik['password']
        );

        if (!$client) {
            return;
        }

        $bw = ORM::for_table(
            "tbl_bandwidth"
        )->find_one($plan['id_bw']);

        if (!$bw) {
            return;
        }

        if ($bw['rate_down_unit'] == 'Kbps') {
            $unitdown = 'K';
        } else {
            $unitdown = 'M';
        }

        if ($bw['rate_up_unit'] == 'Kbps') {
            $unitup = 'K';
        } else {
            $unitup = 'M';
        }

        $rate =
            $bw['rate_up']
            . $unitup
            . "/"
            . $bw['rate_down']
            . $unitdown;

        if (!empty(trim($bw['burst']))) {
            $rate .= ' ' . $bw['burst'];
        }

        if (
            $bw['rate_up'] == '0'
            ||
            $bw['rate_down'] == '0'
        ) {
            $rate = '';
        }

        $addRequest = new RouterOS\Request(
            '/ip/hotspot/user/profile/add'
        );

        $client->sendSync(
            $addRequest
                ->setArgument(
                    'name',
                    $plan['name_plan']
                )
                ->setArgument(
                    'shared-users',
                    $plan['shared_users']
                )
                ->setArgument(
                    'rate-limit',
                    $rate
                )
                ->setArgument(
                    'idle-timeout',
                    'none'
                )
                ->setArgument(
                    'keepalive-timeout',
                    'none'
                )
        );
    }


    /**
     * Ensure Hotspot user-profile shared-users
     * matches billing package.
     */
    function ensureHotspotProfileSharedUsers($client, $plan)
    {
        global $_app_stage;

        if ($_app_stage == 'Demo') {
            return;
        }

        $name = trim(
            (string)($plan['name_plan'] ?? '')
        );

        $shared = (int)(
            $plan['shared_users'] ?? 1
        );

        if ($name === '') {
            return;
        }

        if ($shared < 1) {
            $shared = 1;
        }

        try {

            $printRequest = new RouterOS\Request(
                '/ip/hotspot/user/profile/print',
                RouterOS\Query::where(
                    'name',
                    $name
                )
            );

            $printRequest->setArgument(
                '.proplist',
                '.id,shared-users,keepalive-timeout,idle-timeout'
            );

            $row = $client->sendSync(
                $printRequest
            );

            $id = $row->getProperty('.id');

            if (empty($id)) {
                return;
            }

            $current = (int)$row->getProperty(
                'shared-users'
            );

            $ka = strtolower(
                trim(
                    (string)$row->getProperty(
                        'keepalive-timeout'
                    )
                )
            );

            $idle = strtolower(
                trim(
                    (string)$row->getProperty(
                        'idle-timeout'
                    )
                )
            );

            $needShared =
                ($current !== $shared);

            $needPersist =
                ($ka !== 'none')
                ||
                (
                    $idle !== ''
                    &&
                    $idle !== 'none'
                );

            if (
                !$needShared
                &&
                !$needPersist
            ) {
                return;
            }

            $setRequest = new RouterOS\Request(
                '/ip/hotspot/user/profile/set'
            );

            $setRequest->setArgument(
                'numbers',
                $id
            );

            if ($needShared) {
                $setRequest->setArgument(
                    'shared-users',
                    (string)$shared
                );
            }

            $setRequest->setArgument(
                'idle-timeout',
                'none'
            );

            $setRequest->setArgument(
                'keepalive-timeout',
                'none'
            );

            $client->sendSync(
                $setRequest
            );

        } catch (\Exception $e) {
            // Do not stop customer processing because
            // profile synchronization failed.
        }
    }


    function online_customer($customer, $router_name)
    {
        $mikrotik = $this->info($router_name);

        if (!$mikrotik) {
            return null;
        }

        $client = $this->getClient(
            $mikrotik['ip_address'],
            $mikrotik['username'],
            $mikrotik['password']
        );

        if (!$client) {
            return null;
        }

        $printRequest = new RouterOS\Request(
            '/ip/hotspot/active/print',
            RouterOS\Query::where(
                'user',
                $customer['username']
            )
        );

        $id = $client
            ->sendSync($printRequest)
            ->getProperty('.id');

        return $id;
    }


    function connect_customer(
        $customer,
        $ip,
        $mac_address,
        $router_name
    ) {
        $mikrotik = $this->info($router_name);

        if (!$mikrotik) {
            return;
        }

        $client = $this->getClient(
            $mikrotik['ip_address'],
            $mikrotik['username'],
            $mikrotik['password']
        );

        if (!$client) {
            return;
        }

        $addRequest = new RouterOS\Request(
            '/ip/hotspot/active/login'
        );

        $client->sendSync(
            $addRequest
                ->setArgument(
                    'user',
                    $customer['username']
                )
                ->setArgument(
                    'password',
                    $customer['password']
                )
                ->setArgument(
                    'ip',
                    $ip
                )
                ->setArgument(
                    'mac-address',
                    $mac_address
                )
        );
    }


    function disconnect_customer(
        $customer,
        $router_name
    ) {
        $mikrotik = $this->info($router_name);

        if (!$mikrotik) {
            return;
        }

        $client = $this->getClient(
            $mikrotik['ip_address'],
            $mikrotik['username'],
            $mikrotik['password']
        );

        if (!$client) {
            return;
        }

        $username = (string)(
            $customer['username'] ?? ''
        );

        if ($username === '') {
            return;
        }

        $this->forceCaptivePortal(
            $client,
            $username
        );
    }


    function update_plan($old_plan, $new_plan)
    {
        $mikrotik = $this->info(
            $new_plan['routers']
        );

        if (!$mikrotik) {
            return;
        }

        $client = $this->getClient(
            $mikrotik['ip_address'],
            $mikrotik['username'],
            $mikrotik['password']
        );

        if (!$client) {
            return;
        }

        $printRequest = new RouterOS\Request(
            '/ip/hotspot/user/profile/print .proplist=.id',
            RouterOS\Query::where(
                'name',
                $old_plan['name_plan']
            )
        );

        $profileID = $client
            ->sendSync($printRequest)
            ->getProperty('.id');

        if (empty($profileID)) {

            $this->add_plan(
                $new_plan
            );

        } else {

            $bw = ORM::for_table(
                "tbl_bandwidth"
            )->find_one(
                $new_plan['id_bw']
            );

            if (!$bw) {
                return;
            }

            if ($bw['rate_down_unit'] == 'Kbps') {
                $unitdown = 'K';
            } else {
                $unitdown = 'M';
            }

            if ($bw['rate_up_unit'] == 'Kbps') {
                $unitup = 'K';
            } else {
                $unitup = 'M';
            }

            $rate =
                $bw['rate_up']
                . $unitup
                . "/"
                . $bw['rate_down']
                . $unitdown;

            if (!empty(trim($bw['burst']))) {
                $rate .= ' ' . $bw['burst'];
            }

            if (
                $bw['rate_up'] == '0'
                ||
                $bw['rate_down'] == '0'
            ) {
                $rate = '';
            }

            $setRequest = new RouterOS\Request(
                '/ip/hotspot/user/profile/set'
            );

            $client->sendSync(
                $setRequest
                    ->setArgument(
                        'numbers',
                        $profileID
                    )
                    ->setArgument(
                        'name',
                        $new_plan['name_plan']
                    )
                    ->setArgument(
                        'shared-users',
                        $new_plan['shared_users']
                    )
                    ->setArgument(
                        'rate-limit',
                        $rate
                    )
                    ->setArgument(
                        'idle-timeout',
                        'none'
                    )
                    ->setArgument(
                        'keepalive-timeout',
                        'none'
                    )
                    ->setArgument(
                        'on-login',
                        $new_plan['on_login']
                    )
                    ->setArgument(
                        'on-logout',
                        $new_plan['on_logout']
                    )
            );
        }
    }


    function remove_plan($plan)
    {
        $mikrotik = $this->info(
            $plan['routers']
        );

        if (!$mikrotik) {
            return;
        }

        $client = $this->getClient(
            $mikrotik['ip_address'],
            $mikrotik['username'],
            $mikrotik['password']
        );

        if (!$client) {
            return;
        }

        $printRequest = new RouterOS\Request(
            '/ip/hotspot/user/profile/print .proplist=.id',
            RouterOS\Query::where(
                'name',
                $plan['name_plan']
            )
        );

        $profileID = $client
            ->sendSync($printRequest)
            ->getProperty('.id');

        if (empty($profileID)) {
            return;
        }

        $removeRequest = new RouterOS\Request(
            '/ip/hotspot/user/profile/remove'
        );

        $client->sendSync(
            $removeRequest
                ->setArgument(
                    'numbers',
                    $profileID
                )
        );
    }


    function info($name)
    {
        return ORM::for_table(
            'tbl_routers'
        )->where(
            'name',
            $name
        )->find_one();
    }


    function getClient($ip, $user, $pass)
    {
        global $_app_stage;

        if ($_app_stage == 'Demo') {
            return null;
        }

        $iport = explode(
            ":",
            $ip
        );

        $host = $iport[0];

        $port = null;

        if (isset($iport[1]) && $iport[1] !== '') {
            $port = $iport[1];
        }

        return new RouterOS\Client(
            $host,
            $user,
            $pass,
            $port
        );
    }


    /**
     * Get expiration information for the active recharge.
     *
     * IMPORTANT:
     * The old version called:
     *
     *     MikrotikTimeSync::expiryComment(...)
     *
     * but the MikrotikTimeSync class does not exist in this
     * installation.
     *
     * This implementation performs the formatting locally and
     * therefore does not require that missing class.
     */
    function getExpirationInfo($username, $router_name)
    {
        $recharge = ORM::for_table(
            'tbl_user_recharges'
        )
            ->where(
                'username',
                $username
            )
            ->where(
                'routers',
                $router_name
            )
            ->where(
                'status',
                'on'
            )
            ->order_by_desc(
                'recharged_on'
            )
            ->find_one();

        if (!$recharge) {
            return '';
        }

        $expiration = trim(
            (string)($recharge['expiration'] ?? '')
        );

        $time = trim(
            (string)($recharge['time'] ?? '')
        );

        if ($expiration === '') {
            return '';
        }

        /*
         * Build the expiration value.
         *
         * We intentionally do not depend on a missing class.
         */
        if ($time !== '') {
            $dateValue =
                $expiration . ' ' . $time;
        } else {
            $dateValue = $expiration;
        }

        /*
         * Try to normalize the date to ISO format.
         *
         * If the stored database value is already valid,
         * return:
         *
         *     | Exp: 2026-10-04 14:30:00
         *
         * If PHP cannot parse it, retain the original value
         * instead of crashing the MikroTik update.
         */
        try {

            $date = new DateTime(
                $dateValue,
                new DateTimeZone('Africa/Nairobi')
            );

            return ' | Exp: ' . $date->format(
                'Y-m-d H:i:s'
            );

        } catch (\Exception $e) {

            // Never allow an expiration-formatting error
            // to prevent a MikroTik user from being updated.
            return ' | Exp: ' . $dateValue;
        }
    }


    /**
     * Hotspot limit-uptime = session quota from the plan
     * (Time_Limit), not calendar days.
     *
     * Calendar expiry is enforced by billing cron + kick.
     */
    function resolveLimitUptime($customer, $plan)
    {
        if (
            ($plan['typebp'] ?? '') == 'Limited'
            &&
            in_array(
                ($plan['limit_type'] ?? ''),
                [
                    'Time_Limit',
                    'Both_Limit'
                ],
                true
            )
        ) {

            if (
                ($plan['time_unit'] ?? '') == 'Hrs'
            ) {

                return $plan['time_limit']
                    . ':00:00';

            }

            return '00:'
                . $plan['time_limit']
                . ':00';
        }

        return null;
    }


    function getHotspotUserId($client, $username)
    {
        global $_app_stage;

        if ($_app_stage == 'Demo') {
            return null;
        }

        $printRequest = new RouterOS\Request(
            '/ip/hotspot/user/print'
        );

        $printRequest->setArgument(
            '.proplist',
            '.id'
        );

        $printRequest->setQuery(
            RouterOS\Query::where(
                'name',
                $username
            )
        );

        return $client
            ->sendSync($printRequest)
            ->getProperty('.id');
    }


    function removeHotspotUser($client, $username)
    {
        global $_app_stage;

        if ($_app_stage == 'Demo') {
            return null;
        }

        try {

            $printRequest = new RouterOS\Request(
                '/ip hotspot user print .proplist=.id',
                RouterOS\Query::where(
                    'name',
                    $username
                )
            );

            $responses = $client->sendSync(
                $printRequest
            );

            foreach (
                $responses->getAllOfType(
                    RouterOS\Response::TYPE_DATA
                ) as $row
            ) {

                $userID = $row->getProperty(
                    '.id'
                );

                if (
                    $userID === null
                    ||
                    $userID === ''
                ) {
                    continue;
                }

                try {

                    $removeRequest = new RouterOS\Request(
                        '/ip/hotspot/user/remove'
                    );

                    $client->sendSync(
                        $removeRequest
                            ->setArgument(
                                'numbers',
                                $userID
                            )
                    );

                } catch (\Exception $e) {
                }
            }

        } catch (\Exception $e) {
        }
    }


    /**
     * Update an existing MikroTik Hotspot user.
     *
     * This function is extremely important for reconnects.
     *
     * When customer 5615 buys again, this function updates the
     * existing MikroTik account instead of requiring deletion.
     */
    function updateHotspotUser(
        $client,
        $userId,
        $plan,
        $customer
    ) {
        global $_app_stage;

        if ($_app_stage == 'Demo') {
            return null;
        }

        /*
         * IMPORTANT:
         * getExpirationInfo() no longer calls the missing
         * MikrotikTimeSync class.
         */
        $expiration_info = $this->getExpirationInfo(
            $customer['username'],
            $plan['routers']
        );

        $setRequest = new RouterOS\Request(
            '/ip/hotspot/user/set'
        );

        $setRequest->setArgument(
            'numbers',
            $userId
        );

        $setRequest->setArgument(
            'profile',
            $plan['name_plan']
        );

        /*
         * CRITICAL FOR YOUR 5615 PROBLEM:
         *
         * Always synchronize the current billing-system
         * password to the existing MikroTik Hotspot user.
         */
        $setRequest->setArgument(
            'password',
            $customer['password']
        );

        $setRequest->setArgument(
            'comment',
            $customer['fullname']
            . ' | '
            . implode(
                ', ',
                User::getBillNames($customer['id'])
            )
            . $expiration_info
        );

        $setRequest->setArgument(
            'email',
            $customer['email']
        );

        $uptime = $this->resolveLimitUptime(
            $customer,
            $plan
        );

        if ($uptime !== null) {

            $setRequest->setArgument(
                'limit-uptime',
                $uptime
            );
        }

        if (
            ($plan['typebp'] ?? '') == "Limited"
        ) {

            if (
                $plan['limit_type'] == "Data_Limit"
                ||
                $plan['limit_type'] == "Both_Limit"
            ) {

                if (
                    $plan['data_unit'] == 'GB'
                ) {

                    $datalimit =
                        $plan['data_limit']
                        . "000000000";

                } else {

                    $datalimit =
                        $plan['data_limit']
                        . "000000";
                }

                $setRequest->setArgument(
                    'limit-bytes-total',
                    $datalimit
                );

            } else {

                // Remove old data limit if the new plan
                // no longer has a data limit.
                $setRequest->setArgument(
                    'limit-bytes-total',
                    ''
                );
            }
        } else {

            // Unlimited plan.
            //
            // Remove an old byte limit that might have
            // belonged to the previous package.
            $setRequest->setArgument(
                'limit-bytes-total',
                ''
            );
        }

        /*
         * NOW actually send the updated username/password
         * configuration to MikroTik.
         */
        $client->sendSync(
            $setRequest
        );
    }


    /**
     * Create a new MikroTik Hotspot user.
     */
    function addHotspotUser(
        $client,
        $plan,
        $customer
    ) {
        global $_app_stage;

        if ($_app_stage == 'Demo') {
            return null;
        }

        $expiration_info = $this->getExpirationInfo(
            $customer['username'],
            $plan['routers']
        );

        $comment =
            $customer['fullname']
            . ' | '
            . implode(
                ', ',
                User::getBillNames($customer['id'])
            )
            . $expiration_info;

        $uptime = $this->resolveLimitUptime(
            $customer,
            $plan
        );

        $addRequest = new RouterOS\Request(
            '/ip/hotspot/user/add'
        );

        $addRequest->setArgument(
            'name',
            $customer['username']
        );

        $addRequest->setArgument(
            'profile',
            $plan['name_plan']
        );

        $addRequest->setArgument(
            'password',
            $customer['password']
        );

        $addRequest->setArgument(
            'comment',
            $comment
        );

        $addRequest->setArgument(
            'email',
            $customer['email']
        );

        if ($uptime !== null) {

            $addRequest->setArgument(
                'limit-uptime',
                $uptime
            );
        }

        if (
            ($plan['typebp'] ?? '') == "Limited"
            &&
            (
                $plan['limit_type'] == "Data_Limit"
                ||
                $plan['limit_type'] == "Both_Limit"
            )
        ) {

            if ($plan['data_unit'] == 'GB') {

                $datalimit =
                    $plan['data_limit']
                    . "000000000";

            } else {

                $datalimit =
                    $plan['data_limit']
                    . "000000";
            }

            $addRequest->setArgument(
                'limit-bytes-total',
                $datalimit
            );
        }

        $client->sendSync(
            $addRequest
        );
    }


    function setHotspotUser(
        $client,
        $user,
        $pass
    ) {
        global $_app_stage;

        if ($_app_stage == 'Demo') {
            return null;
        }

        $printRequest = new RouterOS\Request(
            '/ip/hotspot/user/print'
        );

        $printRequest->setArgument(
            '.proplist',
            '.id'
        );

        $printRequest->setQuery(
            RouterOS\Query::where(
                'name',
                $user
            )
        );

        $id = $client
            ->sendSync($printRequest)
            ->getProperty('.id');

        if (empty($id)) {
            return;
        }

        $setRequest = new RouterOS\Request(
            '/ip/hotspot/user/set'
        );

        $setRequest->setArgument(
            'numbers',
            $id
        );

        $setRequest->setArgument(
            'password',
            $pass
        );

        $client->sendSync(
            $setRequest
        );
    }


    function setHotspotUserPackage(
        $client,
        $username,
        $plan_name
    ) {
        global $_app_stage;

        if ($_app_stage == 'Demo') {
            return null;
        }

        $printRequest = new RouterOS\Request(
            '/ip/hotspot/user/print'
        );

        $printRequest->setArgument(
            '.proplist',
            '.id'
        );

        $printRequest->setQuery(
            RouterOS\Query::where(
                'name',
                $username
            )
        );

        $id = $client
            ->sendSync($printRequest)
            ->getProperty('.id');

        if (!empty($id)) {

            $customer = ORM::for_table(
                'tbl_customers'
            )
                ->where(
                    'username',
                    $username
                )
                ->find_one();

            $recharge = ORM::for_table(
                'tbl_user_recharges'
            )
                ->where(
                    'username',
                    $username
                )
                ->where(
                    'status',
                    'on'
                )
                ->order_by_desc(
                    'recharged_on'
                )
                ->find_one();

            $setRequest = new RouterOS\Request(
                '/ip/hotspot/user/set'
            );

            $setRequest->setArgument(
                'numbers',
                $id
            );

            $setRequest->setArgument(
                'profile',
                $plan_name
            );

            if (
                $customer
                &&
                $recharge
            ) {

                $expiration_info =
                    $this->getExpirationInfo(
                        $username,
                        $recharge['routers']
                    );

                $setRequest->setArgument(
                    'comment',
                    $customer['fullname']
                    . ' | '
                    . implode(
                        ', ',
                        User::getBillNames(
                            $customer['id']
                        )
                    )
                    . $expiration_info
                );
            }

            $client->sendSync(
                $setRequest
            );
        }
    }


    function removeHotspotActiveUser(
        $client,
        $username
    ) {
        global $_app_stage;

        if ($_app_stage == 'Demo') {
            return null;
        }

        // Remove ALL active sessions for this username.
        try {

            $onlineRequest = new RouterOS\Request(
                '/ip/hotspot/active/print'
            );

            $onlineRequest->setArgument(
                '.proplist',
                '.id,user'
            );

            $onlineRequest->setQuery(
                RouterOS\Query::where(
                    'user',
                    $username
                )
            );

            $responses = $client->sendSync(
                $onlineRequest
            );

            foreach (
                $responses->getAllOfType(
                    RouterOS\Response::TYPE_DATA
                ) as $row
            ) {

                $id = $row->getProperty(
                    '.id'
                );

                if (
                    $id === null
                    ||
                    $id === ''
                ) {
                    continue;
                }

                try {

                    $removeRequest = new RouterOS\Request(
                        '/ip/hotspot/active/remove'
                    );

                    $client->sendSync(
                        $removeRequest
                            ->setArgument(
                                'numbers',
                                $id
                            )
                    );

                } catch (\Exception $e) {
                }
            }

        } catch (\Exception $e) {
        }
    }


    /**
     * Clear Hotspot login cookies.
     */
    function removeHotspotCookies(
        $client,
        $username
    ) {
        global $_app_stage;

        if ($_app_stage == 'Demo') {
            return null;
        }

        try {

            $printRequest = new RouterOS\Request(
                '/ip/hotspot/cookie/print'
            );

            $printRequest->setArgument(
                '.proplist',
                '.id,user'
            );

            $printRequest->setQuery(
                RouterOS\Query::where(
                    'user',
                    $username
                )
            );

            $responses = $client->sendSync(
                $printRequest
            );

            foreach (
                $responses->getAllOfType(
                    RouterOS\Response::TYPE_DATA
                ) as $row
            ) {

                $id = $row->getProperty(
                    '.id'
                );

                if (
                    $id === null
                    ||
                    $id === ''
                ) {
                    continue;
                }

                try {

                    $removeRequest = new RouterOS\Request(
                        '/ip/hotspot/cookie/remove'
                    );

                    $client->sendSync(
                        $removeRequest
                            ->setArgument(
                                'numbers',
                                $id
                            )
                    );

                } catch (\Exception $e) {
                }
            }

        } catch (\Exception $e) {
        }
    }


    function getIpHotspotUser(
        $client,
        $username
    ) {
        global $_app_stage;

        if ($_app_stage == 'Demo') {
            return null;
        }

        $printRequest = new RouterOS\Request(
            '/ip hotspot active print',
            RouterOS\Query::where(
                'user',
                $username
            )
        );

        return $client
            ->sendSync($printRequest)
            ->getProperty('address');
    }
}
