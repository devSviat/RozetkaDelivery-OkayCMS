<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Init;

use Okay\Admin\Helpers\BackendDeliveriesHelper;
use Okay\Admin\Helpers\BackendOrdersHelper;
use Okay\Admin\Helpers\BackendPaymentsHelper;
use Okay\Admin\Requests\BackendPaymentsRequest;
use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\EntityField;
use Okay\Core\QueryFactory;
use Okay\Core\Scheduler\Schedule;
use Okay\Core\ServiceLocator;
use Okay\Entities\LanguagesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Entities\PaymentsEntity;
use Okay\Helpers\DeliveriesHelper;
use Okay\Helpers\NotifyHelper;
use Okay\Helpers\OrdersHelper;
use Okay\Helpers\ValidateHelper;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\GroupEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\ParcelEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\ReceptionEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Extenders\BackendExtender;
use Okay\Modules\Sviat\RozetkaDelivery\Extenders\FrontExtender;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\DefaultGroups;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\DeliveryFilterHub;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrdersEntityFilters;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelTracking;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateEntity;

class Init extends AbstractInit
{
    public function install()
    {
        $this->setBackendMainController('RozetkaSettingsAdmin');
        $this->setModuleType(MODULE_TYPE_DELIVERY);

        $this->createMissingTables(self::tables());
        $this->addDefaultGroups();
        $this->migrateEntityField(PaymentsEntity::class, new EntityField(CodPayments::FIELD)->setTypeTinyInt(1, false)->setDefault(0));
    }

    public function init()
    {
        $this->addFrontBlock('front_cart_delivery', 'cart_delivery.tpl');
        $this->addFrontBlock('front_cart_payment', 'cart_cod_fee.tpl');
        $this->addFrontBlock('front_scripts_after_validate', 'cart_validation.tpl');
        $this->addFrontBlock('front_email_order_user_contact_info', 'email_delivery.tpl');
        $this->addBackendBlock('email_order_admin_contact_info', 'email_delivery.tpl');
        $this->registerQueueExtension([DeliveriesHelper::class, 'getCartDeliveriesList'], [FrontExtender::class, 'getCartDeliveriesList']);
        $this->registerChainExtension([DeliveriesHelper::class, 'getCartDeliveriesList'], [FrontExtender::class, 'applyQuotes']);
        $this->registerChainExtension([DeliveriesHelper::class, 'prepareDeliveryPriceInfo'], [FrontExtender::class, 'orderPrice']);
        $this->registerChainExtension([ValidateHelper::class, 'getCartValidateError'], [FrontExtender::class, 'getCartValidateError']);
        $this->registerQueueExtension([OrdersHelper::class, 'finalCreateOrderProcedure'], [FrontExtender::class, 'saveOrderDelivery']);
        $this->registerChainExtension([OrdersHelper::class, 'isPaymentMethodOffered'], [FrontExtender::class, 'paymentOffered']);
        foreach ([[OrdersHelper::class, 'getOrderPaymentMethodsList'], [NotifyHelper::class, 'finalEmailOrderUser'], [NotifyHelper::class, 'finalEmailOrderAdmin']] as $hook) {
            $this->registerQueueExtension($hook, [FrontExtender::class, 'assignOrderDelivery']);
        }

        $this->registerBackendController('RozetkaSettingsAdmin');
        $this->addBackendControllerPermission('RozetkaSettingsAdmin', 'delivery');

        // Поле створює install(); реєстрація без перевірки, як у Ukrposhta.
        $this->registerEntityField(PaymentsEntity::class, CodPayments::FIELD);
        $this->registerChainExtension([BackendPaymentsRequest::class, 'postPayment'], [BackendExtender::class, 'paymentPost']);
        $this->registerQueueExtension([BackendPaymentsHelper::class, 'getPaymentMethod'], [BackendExtender::class, 'assignPaymentCod']);
        $this->addBackendBlock('payment_switch_checkboxes', 'payment_cod.tpl');
        $this->registerQueueExtension([BackendDeliveriesHelper::class, 'getDelivery'], [BackendExtender::class, 'deliveryWarning']);
        $this->addBackendBlock('delivery_price_block', 'delivery_warning.tpl');

        // Збереження замовлення ядром знімок не чіпає: на шляху збереження хуків немає, знімок змінює лише OrderPointAdmin.
        $this->registerQueueExtension([BackendOrdersHelper::class, 'findOrder'], [BackendExtender::class, 'assignOrderDelivery']);
        $this->registerQueueExtension([BackendOrdersHelper::class, 'delete'], [BackendExtender::class, 'deleteOrders']);
        $this->addBackendBlock('order_contact', 'parcel_side.tpl');
        $this->addBackendBlock('order_contact', 'order_delivery.tpl');
        $this->addBackendBlock('order_custom_block', 'parcel_main.tpl');
        // Select «Статус доставки» і пошук за номером дає хаб Sviat/DeliveryFilter; на buildFilter — лише масові дії.
        $this->registerChainExtension([BackendOrdersHelper::class, 'buildFilter'], [BackendExtender::class, 'listFilter']);
        $this->registerQueueExtension([BackendOrdersHelper::class, 'findOrders'], [BackendExtender::class, 'listOrders']);
        foreach (DeliveryFilterHub::hooks(DeliveryFilterHub::HELPER) as [$expandable, $extension]) {
            $this->registerChainExtension($expandable, $extension);
        }
        $this->registerEntityFilter(OrdersEntity::class, 'rozetka_without_parcel', OrdersEntityFilters::class, 'filter__rozetka_without_parcel');
        $this->registerEntityFilter(OrdersEntity::class, 'rozetka_status_groups', OrdersEntityFilters::class, 'filter__rozetka_status_groups');
        $this->addBackendBlock('orders_list_name', 'orders_status.tpl');
        $this->registerBackendController('OrderPointAdmin');
        $this->addBackendControllerPermission('OrderPointAdmin', 'orders');
        $this->registerBackendController('ParcelAdmin');
        $this->addBackendControllerPermission('ParcelAdmin', 'orders');
        $this->registerBackendController('ReceptionAdmin');
        $this->addBackendControllerPermission('ReceptionAdmin', 'orders');
        $this->extendBackendMenu('left_orders', ['sviat__rozetka__left_receptions' => ['ReceptionAdmin']]);

        // Кожні 5 хвилин — лише дешева перевірка; runner сам вирішує, чи пора працювати,
        // тож кнопку в адмінці обслуговують за кілька хвилин, а щоденному оновленню не потрібен свій слот.
        $this->registerSchedule(
            new Schedule([DictionaryRunner::class, 'tick'])
                ->name('Rozetka: dictionaries')
                ->time('*/5 * * * *')
                ->overlap(false)
                ->timeout(DictionaryRunner::TASK_TIMEOUT)
        );
        // Дешева перевірка кожні 15 хвилин; сама робота виконується раз на день (див. OrderRowsCleanup::tick).
        $this->registerSchedule(
            new Schedule([OrderRowsCleanup::class, 'tick'])
                ->name('Rozetka: rows of deleted orders')
                ->time('*/15 * * * *')
                ->overlap(false)
                ->timeout(300)
        );
        $this->registerSchedule(
            new Schedule([ParcelTracking::class, 'tick'])
                ->name('Rozetka: tracking')
                ->time('*/15 * * * *')
                ->overlap(false)
                ->timeout(600)
        );
    }

    /** @return array<class-string, list<EntityField>> */
    public static function tables(): array
    {
        return [
            StateEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                new EntityField('name')->setTypeVarchar(64, false)->setIndexUnique(),
                new EntityField('value')->setTypeMediumText()->setNullable(),
                new EntityField('updated_at')->setTypeDatetime(false),
            ],
        ] + self::dictionaryTables() + [
            GroupEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                new EntityField('position')->setTypeInt(11, false)->setDefault(0),
                new EntityField('enabled')->setTypeTinyInt(1, false)->setDefault(1),
                new EntityField('kinds')->setTypeText(),
                new EntityField('name')->setTypeVarchar(255)->setIsLang(),
            ],
            OrderDeliveryEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                new EntityField('order_id')->setTypeInt(11, false)->setIndexUnique(),
                new EntityField('city_id')->setTypeVarchar(36, false),
                new EntityField('city_name')->setTypeVarchar(255, false),
                new EntityField('point_id')->setTypeVarchar(36, false),
                new EntityField('point_title')->setTypeVarchar(255, false),
                new EntityField('point_number')->setTypeVarchar(16),
                new EntityField('point_kind')->setTypeVarchar(16, false),
                new EntityField('prepay_only')->setTypeTinyInt(1, false)->setDefault(0),
                // EntityField ядра не має типу date; пишеться лише Y-m-d (OrderDeliveryStore::date).
                new EntityField('estimated_date')->setTypeDatetime(),
                new EntityField('price')->setTypeDecimal('10,2'),
                new EntityField('price_source')->setTypeVarchar(16),
                new EntityField('cod')->setTypeTinyInt(1, false)->setDefault(0),
                new EntityField('cod_fee')->setTypeDecimal('10,2'),
                new EntityField('updated_at')->setTypeDatetime(false),
            ],
            ParcelEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                new EntityField('order_id')->setTypeInt(11, false)->setIndexUnique(),
                new EntityField('track_id')->setTypeVarchar(32)->setIndexUnique(),
                // Пишеться другим кроком після вставки резерву (потрібен id рядка).
                new EntityField('visible_id')->setTypeVarchar(32)->setIndexUnique(),
                new EntityField('state')->setTypeVarchar(16, false),
                new EntityField('status_code')->setTypeInt(11),
                new EntityField('status_raw')->setTypeVarchar(64),
                new EntityField('status_group')->setTypeVarchar(16),
                new EntityField('partial')->setTypeTinyInt(1, false)->setDefault(0),
                new EntityField('event_name')->setTypeVarchar(255),
                new EntityField('event_at')->setTypeDatetime(),
                new EntityField('arrived_at')->setTypeDatetime(),
                new EntityField('expiration_date')->setTypeDatetime(),
                new EntityField('delivery_date')->setTypeDatetime(),
                new EntityField('shipping_cost')->setTypeDecimal('10,2'),
                new EntityField('payment_fee')->setTypeDecimal('10,2'),
                new EntityField('cod_amount')->setTypeDecimal('10,2'),
                new EntityField('declared_value')->setTypeDecimal('10,2'),
                new EntityField('has_paid')->setTypeTinyInt(1),
                new EntityField('delivery_payer')->setTypeVarchar(8),
                new EntityField('payer_reason')->setTypeVarchar(8),
                new EntityField('point_id')->setTypeVarchar(36),
                new EntityField('reception_id')->setTypeInt(11)->setIndex(),
                new EntityField('details')->setTypeText()->setNullable(),
                new EntityField('history')->setTypeMediumText()->setNullable(),
                new EntityField('created_at')->setTypeDatetime(false),
                new EntityField('reserved_at')->setTypeDatetime(),
                new EntityField('checked_at')->setTypeDatetime(),
            ],
            ReceptionEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                new EntityField('reception_id')->setTypeInt(11, false)->setIndexUnique(),
                new EntityField('status')->setTypeVarchar(16),
                new EntityField('department_id')->setTypeVarchar(36),
                new EntityField('tracks_amount')->setTypeInt(11, false)->setDefault(0),
                new EntityField('created_at')->setTypeDatetime(false),
                new EntityField('checked_at')->setTypeDatetime(),
            ],
        ];
    }

    /** @return array<class-string, list<EntityField>> міста з точками й точки; ключі — UUID Rozetka */
    public static function dictionaryTables(): array
    {
        $loaded = static fn(): array => [
            new EntityField('row_hash')->setTypeVarchar(40, false),
            new EntityField('seen_run')->setTypeInt(11, false)->setDefault(0),
            new EntityField('updated_at')->setTypeDatetime(false),
        ];

        return [
            CityEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeVarchar(36, false),
                new EntityField('region_id')->setTypeVarchar(36),
                new EntityField('district_id')->setTypeVarchar(36),
                new EntityField('type')->setTypeVarchar(16),
                new EntityField('name_ua')->setTypeVarchar(128, false),
                new EntityField('district_ua')->setTypeVarchar(128),
                new EntityField('region_ua')->setTypeVarchar(128),
                new EntityField('search_text')->setTypeVarchar(255, false)->setIndex(),
                new EntityField('points')->setTypeInt(11, false)->setDefault(0),
                ...$loaded(),
            ],
            PointEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeVarchar(36, false),
                new EntityField('city_id')->setTypeVarchar(36, false)->setIndex(),
                new EntityField('number')->setTypeVarchar(16),
                new EntityField('kind')->setTypeVarchar(16, false),
                new EntityField('type_id')->setTypeInt(11, false)->setDefault(0),
                new EntityField('title')->setTypeVarchar(255, false),
                new EntityField('public_name')->setTypeVarchar(255),
                new EntityField('address')->setTypeVarchar(255, false),
                new EntityField('lat')->setTypeDecimal('10,7'),
                new EntityField('lng')->setTypeDecimal('10,7'),
                new EntityField('schedule')->setTypeText(),
                new EntityField('extra_schedule')->setTypeText()->setNullable(),
                new EntityField('max_weight')->setTypeDecimal('10,3'),
                new EntityField('max_volume_weight')->setTypeDecimal('10,3'),
                new EntityField('max_length')->setTypeDecimal('10,3'),
                new EntityField('max_cost')->setTypeDecimal('10,2'),
                new EntityField('can_receive')->setTypeTinyInt(1, false)->setDefault(0),
                new EntityField('can_give_out')->setTypeTinyInt(1, false)->setDefault(0),
                new EntityField('prepay_only')->setTypeTinyInt(1, false)->setDefault(0),
                ...$loaded(),
            ],
        ];
    }

    /**
     * Видалення модуля лишає його таблиці, а migrateEntityTable() — це голий CREATE TABLE:
     * повторне встановлення засмітило б лог повідомленнями "table already exists".
     *
     * @param array<class-string, list<EntityField>> $tables
     */
    protected function createMissingTables(array $tables): void
    {
        $serviceLocator = ServiceLocator::getInstance();
        /** @var Database $db */
        $db = $serviceLocator->getService(Database::class);
        $db->query($serviceLocator->getService(QueryFactory::class)->newSqlQuery()->setStatement('SHOW TABLES'));
        $existing = [];
        foreach ($db->results() as $row) {
            $existing[] = (string)current((array)$row);
        }

        $prefix = (string)$serviceLocator->getService(Config::class)->get('db_prefix');
        foreach (self::missingTables($tables, $existing, $prefix) as $entityClass => $fields) {
            $this->migrateEntityTable($entityClass, $fields);
        }
    }

    /**
     * @param array<class-string, list<EntityField>> $tables
     * @param list<string> $existing назви таблиць бази з префіксом
     * @return array<class-string, list<EntityField>>
     */
    public static function missingTables(array $tables, array $existing, string $prefix): array
    {
        // Ядро підставляє префікс таблиць замість початкового "__".
        return array_filter(
            $tables,
            static fn(string $entityClass): bool => !in_array($prefix . substr($entityClass::getTable(), 2), $existing, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function addDefaultGroups(): void
    {
        $serviceLocator = ServiceLocator::getInstance();
        /** @var EntityFactory $entityFactory */
        $entityFactory = $serviceLocator->getService(EntityFactory::class);
        /** @var Languages $languages */
        $languages = $serviceLocator->getService(Languages::class);
        $groups = $entityFactory->get(GroupEntity::class);
        // Видалення модуля лишає його таблиці: повторне встановлення не повинне додати типові групи вдруге.
        if ($groups->count() > 0) {
            return;
        }
        $siteLanguages = $entityFactory->get(LanguagesEntity::class)->find();
        $initialLanguage = $languages->getLangId();

        foreach (DefaultGroups::rows() as $row) {
            $id = $groups->add([
                'position' => $row['position'],
                'enabled' => $row['enabled'],
                'kinds' => json_encode($row['kinds']),
                'name' => DefaultGroups::name($row, ''),
            ]);
            foreach ($siteLanguages as $language) {
                $languages->setLangId((int)$language->id);
                $groups->update($id, ['name' => DefaultGroups::name($row, (string)$language->label)]);
            }
        }
        $languages->setLangId($initialLanguage);
    }
}
