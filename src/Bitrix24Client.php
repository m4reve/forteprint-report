<?php

namespace App;

final class Bitrix24Client
{
    private $contacts = array();
    private $companies = array();
    private $users = array();
    private $http;
    private $webhookBase;
    private $portalBaseUrl;

    public function __construct(HttpClient $http, $webhookBase, $portalBaseUrl = null)
    {
        $this->http = $http;
        $this->webhookBase = $webhookBase;
        $this->portalBaseUrl = $portalBaseUrl !== null && trim((string) $portalBaseUrl) !== ''
            ? rtrim((string) $portalBaseUrl, '/')
            : $this->detectPortalBaseUrl($webhookBase);
    }

    public function call($method, array $params = array())
    {
        $url = rtrim($this->webhookBase, '/') . '/' . $method . '.json';
        $response = $this->http->requestJson('POST', $url, [], $params);
        if (isset($response['error'])) {
            $description = isset($response['error_description']) ? $response['error_description'] : $response['error'];
            throw new \RuntimeException("Bitrix24 {$method}: {$description}");
        }
        return $response;
    }

    public function dealFields()
    {
        $response = $this->call('crm.item.fields', ['entityTypeId' => 2, 'useOriginalUfNames' => 'N']);
        return isset($response['result']['fields']) ? $response['result']['fields'] : array();
    }

    public function listDeals(array $fieldNames, $updatedSince, $categoryId, array $stageIds, $assignedById = null)
    {
        $filter = [];
        if ($updatedSince !== null) {
            $filter['>=updatedTime'] = $updatedSince;
        }
        if ($categoryId !== null) {
            $filter['categoryId'] = (int) $categoryId;
        }
        if ($stageIds !== array()) {
            $filter['@stageId'] = array_values($stageIds);
        }
        if ($assignedById !== null && (int) $assignedById > 0) {
            $filter['assignedById'] = (int) $assignedById;
        }

        $select = array_values(array_unique(array_merge([
            'id', 'title', 'createdTime', 'updatedTime', 'categoryId', 'stageId',
            'assignedById', 'sourceId', 'opportunity', 'closedate', 'contactIds',
            'contactId', 'companyId', 'comments',
        ], $fieldNames)));

        $items = [];
        $start = 0;
        do {
            $response = $this->call('crm.item.list', [
                'entityTypeId' => 2,
                'select' => $select,
                'filter' => $filter,
                'order' => ['updatedTime' => 'ASC', 'id' => 'ASC'],
                'start' => $start,
                'useOriginalUfNames' => 'N',
            ]);
            $pageItems = isset($response['result']['items']) ? $response['result']['items'] : array();
            foreach ($pageItems as $item) {
                $items[] = $item;
            }
            $next = null;
            if (isset($response['next'])) {
                $next = $response['next'];
            } elseif (isset($response['result']['next'])) {
                $next = $response['result']['next'];
            }
            $start = $next === null ? -1 : (int) $next;
        } while ($start >= 0);

        return $items;
    }

    /**
     * Возвращает первые даты входа сделок в указанные стадии:
     * [stageId => [dealId => ISO datetime]].
     */
    public function stageEnteredDates(array $dealIds, array $stageIds)
    {
        $normalizedDealIds = array();
        foreach ($dealIds as $dealId) {
            $dealId = (int) $dealId;
            if ($dealId > 0) {
                $normalizedDealIds[$dealId] = $dealId;
            }
        }
        $normalizedStageIds = array();
        foreach ($stageIds as $stageId) {
            $stageId = trim((string) $stageId);
            if ($stageId !== '') {
                $normalizedStageIds[$stageId] = $stageId;
            }
        }

        $result = array();
        foreach ($normalizedStageIds as $stageId) {
            $result[$stageId] = array();
        }
        if ($normalizedDealIds === array() || $normalizedStageIds === array()) {
            return $result;
        }

        foreach (array_chunk(array_values($normalizedDealIds), 50) as $dealChunk) {
            $start = 0;
            do {
                $response = $this->call('crm.stagehistory.list', array(
                    'entityTypeId' => 2,
                    'order' => array('ID' => 'ASC'),
                    'filter' => array(
                        '@OWNER_ID' => $dealChunk,
                        '@STAGE_ID' => array_values($normalizedStageIds),
                    ),
                    'select' => array('ID', 'OWNER_ID', 'STAGE_ID', 'CREATED_TIME'),
                    'start' => $start,
                ));
                $items = isset($response['result']['items']) ? $response['result']['items'] : array();
                foreach ($items as $item) {
                    $ownerId = isset($item['OWNER_ID']) ? $item['OWNER_ID'] : (isset($item['ownerId']) ? $item['ownerId'] : '');
                    $stageId = isset($item['STAGE_ID']) ? $item['STAGE_ID'] : (isset($item['stageId']) ? $item['stageId'] : '');
                    $createdTime = isset($item['CREATED_TIME']) ? $item['CREATED_TIME'] : (isset($item['createdTime']) ? $item['createdTime'] : '');
                    $ownerId = (string) $ownerId;
                    $stageId = (string) $stageId;
                    if ($ownerId !== '' && $createdTime !== '' && isset($result[$stageId])) {
                        if (!isset($result[$stageId][$ownerId])) {
                            $result[$stageId][$ownerId] = (string) $createdTime;
                        } else {
                            $existingTimestamp = strtotime($result[$stageId][$ownerId]);
                            $candidateTimestamp = strtotime((string) $createdTime);
                            if ($candidateTimestamp !== false && ($existingTimestamp === false || $candidateTimestamp < $existingTimestamp)) {
                                $result[$stageId][$ownerId] = (string) $createdTime;
                            }
                        }
                    }
                }
                $next = null;
                if (isset($response['next'])) {
                    $next = $response['next'];
                } elseif (isset($response['result']['next'])) {
                    $next = $response['result']['next'];
                }
                $start = $next === null ? -1 : (int) $next;
            } while ($start >= 0);
        }

        return $result;
    }

    public function dealUrl($dealId)
    {
        $dealId = (int) $dealId;
        if ($dealId <= 0 || $this->portalBaseUrl === '') {
            return '';
        }
        return $this->portalBaseUrl . '/crm/deal/details/' . $dealId . '/';
    }

    public function contactUrl($contactId)
    {
        $contactId = (int) $contactId;
        if ($contactId <= 0 || $this->portalBaseUrl === '') {
            return '';
        }
        return $this->portalBaseUrl . '/crm/contact/details/' . $contactId . '/';
    }

    public function clientForDeal(array $deal)
    {
        $contactIds = isset($deal['contactIds']) ? $deal['contactIds'] : array();
        if (!is_array($contactIds)) {
            $contactIds = [$contactIds];
        }
        if ($contactIds === [] && !empty($deal['contactId'])) {
            $contactIds = [$deal['contactId']];
        }

        foreach ($contactIds as $contactId) {
            if (!$contactId) {
                continue;
            }
            $contact = $this->getItem(3, (int) $contactId, $this->contacts);
            if ($contact !== []) {
                return [
                    'name' => $this->personName($contact),
                    'email' => $this->firstMultifield($contact, 'EMAIL') ?: (string) (isset($contact['email']) ? $contact['email'] : ''),
                ];
            }
        }

        if (!empty($deal['companyId'])) {
            $company = $this->getItem(4, (int) $deal['companyId'], $this->companies);
            return [
                'name' => (string) (isset($company['title']) ? $company['title'] : ''),
                'email' => $this->firstMultifield($company, 'EMAIL') ?: (string) (isset($company['email']) ? $company['email'] : ''),
            ];
        }
        return ['name' => '', 'email' => ''];
    }

    public function userName($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return '';
        }
        if (!array_key_exists($id, $this->users)) {
            $response = $this->call('user.get', ['ID' => $id]);
            $this->users[$id] = isset($response['result'][0]) ? $response['result'][0] : array();
        }
        $user = $this->users[$id];
        return trim(implode(' ', array_filter([
            isset($user['NAME']) ? $user['NAME'] : '',
            isset($user['LAST_NAME']) ? $user['LAST_NAME'] : '',
        ], function ($v) { return $v !== ''; })));
    }

    public function categoryNames()
    {
        $response = $this->call('crm.category.list', ['entityTypeId' => 2]);
        $result = [];
        $categories = isset($response['result']['categories']) ? $response['result']['categories'] : array();
        foreach ($categories as $category) {
            $id = isset($category['id']) ? $category['id'] : '';
            $name = isset($category['name']) ? $category['name'] : '';
            $result[(string) $id] = (string) $name;
        }
        return $result;
    }

    public function sourceNames()
    {
        $response = $this->call('crm.status.list', ['filter' => ['ENTITY_ID' => 'SOURCE']]);
        return $this->statusNames($response);
    }

    private function statusNames(array $response)
    {
        $result = [];
        $statuses = isset($response['result']) ? $response['result'] : array();
        foreach ($statuses as $status) {
            $id = isset($status['STATUS_ID']) ? $status['STATUS_ID'] : (isset($status['statusId']) ? $status['statusId'] : '');
            $name = isset($status['NAME']) ? $status['NAME'] : (isset($status['name']) ? $status['name'] : '');
            $id = (string) $id;
            $name = (string) $name;
            if ($id !== '') {
                $result[$id] = $name;
            }
        }
        return $result;
    }

    private function getItem($entityTypeId, $id, array &$cache)
    {
        if (!array_key_exists($id, $cache)) {
            $response = $this->call('crm.item.get', ['entityTypeId' => $entityTypeId, 'id' => $id]);
            $cache[$id] = isset($response['result']['item']) ? $response['result']['item'] : array();
        }
        return $cache[$id];
    }

    private function personName(array $contact)
    {
        $name = trim(implode(' ', array_filter([
            isset($contact['name']) ? $contact['name'] : '',
            isset($contact['secondName']) ? $contact['secondName'] : '',
            isset($contact['lastName']) ? $contact['lastName'] : '',
        ], function ($v) { return $v !== ''; })));
        return $name !== '' ? $name : (string) (isset($contact['title']) ? $contact['title'] : '');
    }

    private function firstMultifield(array $item, $type)
    {
        $fields = isset($item['fm']) ? $item['fm'] : array();
        foreach ($fields as $entry) {
            if (strtoupper((string) (isset($entry['typeId']) ? $entry['typeId'] : '')) === $type) {
                return (string) (isset($entry['value']) ? $entry['value'] : '');
            }
        }
        return '';
    }

    private function detectPortalBaseUrl($webhookBase)
    {
        $parts = parse_url((string) $webhookBase);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $parts['host'] . $port;
    }

}
