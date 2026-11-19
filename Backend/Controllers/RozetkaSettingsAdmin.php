<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\CodSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\DictionaryStatusView;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\KeySettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\KeySettingsView;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\SenderPoints;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\SenderSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\ShippingSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PopularCities;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\DictionaryCounts;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsInput;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsStore;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsView;

/**
 * Сторінка налаштувань модуля (право `delivery`). Кожен блок — окрема форма, `rozetka_action` каже, яка.
 * Перегляд сторінки не звертається до Rozetka: у мережу йдуть лише дії POST.
 */
class RozetkaSettingsAdmin extends IndexAdmin
{
    /** Телефон форми випуску повертається у форму (не секрет), пароль — ніколи. */
    private string $issuePhone = '';
    /** @var array<string, string>|null надіслане з помилками */
    private ?array $senderInput = null;
    /** @var array<string, string|bool>|null */
    private ?array $shippingInput = null;

    public function fetch(
        KeySettings $keySettings,
        KeySettingsView $keyView,
        SenderSettings $senderSettings,
        SenderPoints $senderPoints,
        ShippingSettings $shippingSettings,
        CodSettings $codSettings,
        DictionaryStatusView $dictionaryView,
        DictionaryRunner $dictionaryRunner,
        GroupsStore $groupsStore,
        PopularCities $popularCities,
        DictionaryCounts $dictionaryCounts,
    ) {
        if ($this->request->method('post')) {
            // Сток 4.5.3 перевіряє session_id лише після виконання контролера, тож підроблений POST уже встиг би спрацювати.
            if (!$this->request->checkSession()) {
                $this->response->setStatusCode(403);
                $this->response->setContent('Session expired', RESPONSE_TEXT);

                return;
            }
            $this->handle($keySettings, $senderSettings, $shippingSettings, $codSettings, $dictionaryRunner, $groupsStore, $popularCities);
        }

        $this->design->assign('rozetka_access', $keyView->build());
        $this->design->assign('rozetka_issue', ['phone' => $this->issuePhone]);
        $sender = $this->senderInput ?? $senderSettings->read();
        $this->design->assign('rozetka_sender', [
            'values' => $sender,
            'city' => $senderPoints->city($sender['city_id']),
            'points' => $senderPoints->forCity($sender['city_id']),
        ]);
        $shipping = $this->shippingInput ?? ShippingSettings::form($shippingSettings->read());
        $this->design->assign('rozetka_shipping', [
            'values' => $shipping,
            'cutoff_choices' => ShippingSettings::cutoffChoices((string)$shipping['cutoff']),
        ]);
        $this->design->assign('rozetka_cod', $codSettings->read());
        $this->design->assign('rozetka_dict', $dictionaryView->build());
        $this->design->assign('rozetka_groups', GroupsView::vars($groupsStore->all(), $dictionaryCounts->byKind()));
        $this->design->assign('rozetka_popular', $popularCities->list());
        $this->response->setContent($this->design->fetch('settings.tpl'));
    }

    /** Пошук міста для вибору точки відправника (`controller=…@senderCities&q=`). */
    public function senderCities(SenderPoints $points)
    {
        $query = $this->request->get('q');
        $found = is_string($query) ? $points->searchCities($query) : [];
        $this->response->setContent(json_encode(['cities' => $found], JSON_UNESCAPED_UNICODE), RESPONSE_JSON);
    }

    /** Точки міста, що приймають відправлення (`controller=…@senderPoints&city_id=`). */
    public function senderPoints(SenderPoints $points)
    {
        $cityId = $this->request->get('city_id');
        $found = is_string($cityId) ? $points->forCity(strtolower(trim($cityId))) : [];
        $this->response->setContent(json_encode(['points' => $found], JSON_UNESCAPED_UNICODE), RESPONSE_JSON);
    }

    private function handle(
        KeySettings $keySettings,
        SenderSettings $senderSettings,
        ShippingSettings $shippingSettings,
        CodSettings $codSettings,
        DictionaryRunner $dictionaryRunner,
        GroupsStore $groupsStore,
        PopularCities $popularCities,
    ): void {
        $action = $this->request->post('rozetka_action');
        switch ($action) {
            case 'save_key':
            case 'check_key':
                $saved = $keySettings->save($_POST);
                if ($saved['errors'] !== []) {
                    $this->design->assign('rozetka_errors', $saved['errors']);
                    break;
                }
                // Новий токен уже перевірено під час збереження: друга перевірка лише повторила б той самий запит.
                $check = $saved['check'];
                if ($check === null && $action === 'check_key') {
                    $check = $keySettings->check();
                }
                if ($check !== null) {
                    // Текст Rozetka показується лише у відповіді на цю перевірку й у стан не пишеться.
                    $this->design->assign('rozetka_check_message', $check['message']);
                }
                $this->design->assign('rozetka_saved', $action === 'save_key');
                break;
            case 'issue_token':
                $phone = $this->request->post(KeySettings::PHONE_FIELD);
                $this->issuePhone = is_string($phone) ? mb_substr(trim($phone), 0, 32) : '';
                $issued = $keySettings->issue($_POST);
                $this->design->assign('rozetka_issue_errors', $issued['errors']);
                $this->design->assign('rozetka_issue_warning', $issued['warning']);
                $this->design->assign('rozetka_issued', $issued['errors'] === []);
                if ($issued['errors'] === []) {
                    $this->issuePhone = '';
                }
                break;
            case 'revoke_token':
                $revoked = $keySettings->revoke();
                $this->design->assign('rozetka_revoke_errors', $revoked['errors']);
                $this->design->assign('rozetka_revoked', $revoked['errors'] === []);
                break;
            case 'sender':
                $errors = $senderSettings->save($_POST);
                $this->senderInput = $errors === [] ? null : SenderSettings::fromPost($_POST);
                $this->design->assign('rozetka_sender_errors', $errors);
                $this->design->assign('rozetka_sender_saved', $errors === []);
                break;
            case 'shipping':
                $errors = $shippingSettings->save($_POST);
                $this->shippingInput = $errors === [] ? null : ShippingSettings::formFromPost($_POST);
                $this->design->assign('rozetka_shipping_errors', $errors);
                $this->design->assign('rozetka_shipping_saved', $errors === []);
                break;
            case 'cod':
                $errors = $codSettings->save($_POST);
                $this->design->assign('rozetka_cod_errors', $errors);
                $this->design->assign('rozetka_cod_saved', $errors === []);
                break;
            case 'dictionaries_run':
                // Лише ставить запит у чергу: обхід робить завдання планувальника, а не цей запит.
                $dictionaryRunner->requestRun();
                $this->design->assign('rozetka_dict_requested', true);
                break;
            case 'groups':
                $input = GroupsInput::parse($_POST);
                if ($input['errors'] === []) {
                    $groupsStore->save($input['groups']);
                    $this->design->assign('rozetka_groups_saved', true);
                } else {
                    $this->design->assign('rozetka_groups_errors', $input['errors']);
                }
                break;
            case 'popular':
                $ids = $this->request->post('popular');
                $popularCities->save(is_array($ids) ? array_values($ids) : []);
                $this->design->assign('rozetka_popular_saved', true);
                break;
        }
    }
}
