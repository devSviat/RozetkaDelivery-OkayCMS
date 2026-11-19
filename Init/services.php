<?php

namespace Okay\Modules\Sviat\RozetkaDelivery;

use Okay\Core\BackendTranslations;
use Okay\Core\Cart;
use Okay\Core\FrontTranslations;
use Okay\Core\Database;
use Okay\Core\Design;
use Okay\Core\Money;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\EntityFactory;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\QueryFactory;
use Okay\Core\Settings;
use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\CredentialsProvider;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\CurlTransport;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\HttpTransport;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RequestBudget;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Okay\Modules\Sviat\RozetkaDelivery\Api\TokenIssuer;
use Okay\Modules\Sviat\RozetkaDelivery\Api\TokenProvider;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\CodSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\DictionaryStatusView;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\KeySettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\KeySettingsView;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\SenderPoints;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\SenderSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\ShippingSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CitySearch;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\DictionaryReady;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PointList;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PopularCities;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\UahTotal;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\DictionaryCounts;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\StatusDictionary;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Store\DbDictionaryStore;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Store\DictionaryStore;
use Okay\Modules\Sviat\RozetkaDelivery\Extenders\BackendExtender;
use Okay\Modules\Sviat\RozetkaDelivery\Extenders\FrontExtender;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsStore;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\RozetkaDelivery\Integration\RozetkaOrders;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\DbExistingOrders;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\ExistingOrders;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderParcel;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderPointChange;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\RozetkaDelivery\Extenders\DeliveryFilterExtender;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrdersMassAction;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\AmountDue;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\MassOrders;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\MassOrderSource;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\MassParcels;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\Labels;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDrafts;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssues;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelLookup;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelRows;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelService;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelStore;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelTracking;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelView;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionPrint;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionRows;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionService;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionStore;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\CartQuotes;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryDate;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\QuoteSession;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\DbMutex;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\ModuleState;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\Mutex;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;
use Psr\Log\LoggerInterface;

$now = static fn(): int => time();

return [
    ModuleState::class => [
        'class' => ModuleState::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    StateStorage::class => [
        'class' => ModuleState::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    DbMutex::class => [
        'class' => DbMutex::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    Mutex::class => [
        'class' => DbMutex::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    HttpTransport::class => [
        'class' => CurlTransport::class,
        'arguments' => [],
    ],
    CredentialsProvider::class => [
        'class' => CredentialsProvider::class,
        'arguments' => [new SR(Settings::class)],
    ],
    AuthFlags::class => [
        'class' => AuthFlags::class,
        'arguments' => [new SR(StateStorage::class)],
    ],
    TokenProvider::class => [
        'class' => TokenProvider::class,
        'arguments' => [new SR(CredentialsProvider::class), new SR(StateStorage::class), new SR(AuthFlags::class), $now],
    ],
    RequestBudget::class => [
        'class' => RequestBudget::class,
        'arguments' => [new SR(StateStorage::class), new SR(Mutex::class), $now],
    ],
    RozetkaClient::class => [
        'class' => RozetkaClient::class,
        'arguments' => [
            new SR(HttpTransport::class),
            new SR(TokenProvider::class),
            new SR(RequestBudget::class),
            new SR(CredentialsProvider::class),
            new SR(LoggerInterface::class),
        ],
    ],
    KeyChecker::class => [
        'class' => KeyChecker::class,
        'arguments' => [
            new SR(RozetkaClient::class),
            new SR(TokenProvider::class),
            new SR(CredentialsProvider::class),
            new SR(StateStorage::class),
            new SR(AuthFlags::class),
            $now,
        ],
    ],
    TokenIssuer::class => [
        'class' => TokenIssuer::class,
        'arguments' => [new SR(RozetkaClient::class), new SR(StateStorage::class), $now],
    ],
    DbDictionaryStore::class => [
        'class' => DbDictionaryStore::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    DictionaryStore::class => [
        'class' => DbDictionaryStore::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    StatusDictionary::class => [
        'class' => StatusDictionary::class,
        'arguments' => [new SR(StateStorage::class)],
    ],
    DictionaryRunner::class => [
        'class' => DictionaryRunner::class,
        'arguments' => [
            new SR(RozetkaClient::class),
            new SR(DictionaryStore::class),
            new SR(StatusDictionary::class),
            new SR(StateStorage::class),
            new SR(Mutex::class),
            $now,
        ],
    ],
    DictionaryCounts::class => [
        'class' => DictionaryCounts::class,
        'arguments' => [new SR(EntityFactory::class), new SR(Database::class), new SR(QueryFactory::class)],
    ],
    KeySettings::class => [
        'class' => KeySettings::class,
        'arguments' => [
            new SR(Settings::class),
            new SR(TokenProvider::class),
            new SR(StateStorage::class),
            new SR(AuthFlags::class),
            new SR(KeyChecker::class),
            new SR(TokenIssuer::class),
            new SR(RozetkaClient::class),
            new SR(CredentialsProvider::class),
        ],
    ],
    KeySettingsView::class => [
        'class' => KeySettingsView::class,
        'arguments' => [new SR(CredentialsProvider::class), new SR(KeyChecker::class), new SR(AuthFlags::class), $now],
    ],
    SenderPoints::class => [
        'class' => SenderPoints::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    SenderSettings::class => [
        'class' => SenderSettings::class,
        'arguments' => [new SR(Settings::class), new SR(SenderPoints::class)],
    ],
    ShippingSettings::class => [
        'class' => ShippingSettings::class,
        'arguments' => [new SR(Settings::class)],
    ],
    CodSettings::class => [
        'class' => CodSettings::class,
        'arguments' => [new SR(Settings::class), $now],
    ],
    CodPayments::class => [
        'class' => CodPayments::class,
        'arguments' => [new SR(EntityFactory::class), new SR(CodSettings::class)],
    ],
    DictionaryStatusView::class => [
        'class' => DictionaryStatusView::class,
        'arguments' => [new SR(StateStorage::class), new SR(DictionaryCounts::class), new SR(StatusDictionary::class), $now],
    ],
    GroupsStore::class => [
        'class' => GroupsStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    PopularCities::class => [
        'class' => PopularCities::class,
        'arguments' => [new SR(StateStorage::class), new SR(EntityFactory::class)],
    ],
    CitySearch::class => [
        'class' => CitySearch::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    PointList::class => [
        'class' => PointList::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class), new SR(GroupsStore::class), new SR(Settings::class), $now],
    ],
    UahTotal::class => [
        'class' => UahTotal::class,
        'arguments' => [new SR(EntityFactory::class), new SR(Money::class)],
    ],
    DictionaryReady::class => [
        'class' => DictionaryReady::class,
        'arguments' => [new SR(StateStorage::class), new SR(EntityFactory::class)],
    ],
    CartSelectionValidator::class => [
        'class' => CartSelectionValidator::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Module::class),
            new SR(GroupsStore::class),
            new SR(CodPayments::class),
            new SR(DictionaryReady::class),
            new SR(Settings::class),
        ],
    ],
    QuoteSession::class => [
        'class' => QuoteSession::class,
        'arguments' => [],
    ],
    DeliveryDate::class => [
        'class' => DeliveryDate::class,
        'arguments' => [new SR(RozetkaClient::class), new SR(StateStorage::class), new SR(Settings::class), new SR(QuoteSession::class), $now],
    ],
    CartQuotes::class => [
        'class' => CartQuotes::class,
        'arguments' => [new SR(Settings::class), new SR(Module::class), new SR(Money::class), new SR(EntityFactory::class)],
    ],
    OrderDeliveryStore::class => [
        'class' => OrderDeliveryStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    OrderDeliveryLines::class => [
        'class' => OrderDeliveryLines::class,
        'arguments' => [new SR(EntityFactory::class), new SR(Module::class), new SR(OrderDeliveryStore::class), new SR(CodPayments::class)],
    ],
    OrderParcel::class => [
        'class' => OrderParcel::class,
        'arguments' => [new SR(EntityFactory::class), new SR(UahTotal::class)],
    ],
    OrderPointChange::class => [
        'class' => OrderPointChange::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Module::class),
            new SR(GroupsStore::class),
            new SR(OrderDeliveryStore::class),
            new SR(OrderParcel::class),
            new SR(CartQuotes::class),
            new SR(OrderDeliveryLines::class),
            new SR(Settings::class),
            new SR(CodPayments::class),
            new SR(DeliveryDate::class),
            new SR(ParcelRows::class),
        ],
    ],
    ExistingOrders::class => [
        'class' => DbExistingOrders::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    OrderRowsCleanup::class => [
        'class' => OrderRowsCleanup::class,
        'arguments' => [new SR(EntityFactory::class), new SR(StateStorage::class), new SR(ExistingOrders::class)],
    ],
    FrontExtender::class => [
        'class' => FrontExtender::class,
        'arguments' => [
            new SR(Design::class),
            new SR(Settings::class),
            new SR(Module::class),
            new SR(EntityFactory::class),
            new SR(FrontTranslations::class),
            new SR(CartSelectionValidator::class),
            new SR(OrderDeliveryStore::class),
            new SR(Cart::class),
            new SR(CartQuotes::class),
            new SR(Money::class),
            new SR(UahTotal::class),
            new SR(PopularCities::class),
            new SR(DictionaryReady::class),
            new SR(OrderDeliveryLines::class),
            new SR(CodPayments::class),
            new SR(CodSettings::class),
            new SR(DeliveryDate::class),
            new SR(QuoteSession::class),
        ],
    ],
    BackendExtender::class => [
        'class' => BackendExtender::class,
        'arguments' => [
            new SR(Design::class),
            new SR(Request::class),
            new SR(Module::class),
            new SR(EntityFactory::class),
            new SR(CodSettings::class),
            new SR(BackendTranslations::class),
            new SR(OrderDeliveryStore::class),
            new SR(OrderRowsCleanup::class),
            new SR(OrderDeliveryLines::class),
            new SR(ParcelRows::class),
            new SR(ParcelView::class),
            new SR(Modules::class),
            new SR(Response::class),
            new SR(OrdersMassAction::class),
        ],
    ],
    AmountDue::class => [
        'class' => AmountDue::class,
        'arguments' => [],
    ],
    ParcelDrafts::class => [
        'class' => ParcelDrafts::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(OrderDeliveryLines::class),
            new SR(OrderDeliveryStore::class),
            new SR(OrderParcel::class),
            new SR(UahTotal::class),
            new SR(CodPayments::class),
            new SR(Settings::class),
            new SR(AmountDue::class),
        ],
    ],
    ParcelStore::class => [
        'class' => ParcelStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    ParcelRows::class => [
        'class' => ParcelStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    ParcelIssues::class => [
        'class' => ParcelIssues::class,
        'arguments' => [],
    ],
    ParcelService::class => [
        'class' => ParcelService::class,
        'arguments' => [
            new SR(RozetkaClient::class),
            new SR(ParcelRows::class),
            new SR(ParcelIssues::class),
            new SR(LoggerInterface::class),
            $now,
        ],
    ],
    ParcelLookup::class => [
        'class' => ParcelLookup::class,
        'arguments' => [
            new SR(RozetkaClient::class),
            new SR(ParcelRows::class),
            new SR(ParcelIssues::class),
            new SR(LoggerInterface::class),
            $now,
        ],
    ],
    Labels::class => [
        'class' => Labels::class,
        'arguments' => [new SR(RozetkaClient::class), new SR(LoggerInterface::class), $now],
    ],
    ParcelTracking::class => [
        'class' => ParcelTracking::class,
        'arguments' => [
            new SR(RozetkaClient::class),
            new SR(ParcelRows::class),
            new SR(StatusDictionary::class),
            new SR(Mutex::class),
            new SR(AuthFlags::class),
            new SR(CredentialsProvider::class),
            new SR(StateStorage::class),
            new SR(ParcelIssues::class),
            new SR(LoggerInterface::class),
            $now,
            new SR(ReceptionRows::class),
            new SR(ReceptionService::class),
        ],
    ],
    ParcelView::class => [
        'class' => ParcelView::class,
        'arguments' => [
            new SR(OrderDeliveryLines::class),
            new SR(ParcelRows::class),
            new SR(ParcelDrafts::class),
            new SR(AuthFlags::class),
            new SR(CodSettings::class),
            $now,
            new SR(ReceptionRows::class),
        ],
    ],
    MassOrders::class => [
        'class' => MassOrderSource::class,
        'arguments' => [new SR(EntityFactory::class), new SR(OrderDeliveryLines::class), new SR(ParcelDrafts::class)],
    ],
    MassParcels::class => [
        'class' => MassParcels::class,
        'arguments' => [new SR(ParcelService::class), new SR(ParcelRows::class), new SR(MassOrders::class), new SR(LoggerInterface::class)],
    ],
    OrdersMassAction::class => [
        'class' => OrdersMassAction::class,
        'arguments' => [new SR(MassParcels::class), new SR(Labels::class), new SR(ParcelRows::class), new SR(LoggerInterface::class), new SR(ReceptionService::class)],
    ],
    DeliveryFilterExtender::class => [
        'class' => DeliveryFilterExtender::class,
        'arguments' => [new SR(ParcelRows::class), new SR(BackendTranslations::class), new SR(Module::class), new SR(Modules::class)],
    ],
    ReceptionRows::class => [
        'class' => ReceptionStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    ReceptionService::class => [
        'class' => ReceptionService::class,
        'arguments' => [
            new SR(RozetkaClient::class),
            new SR(ParcelRows::class),
            new SR(ReceptionRows::class),
            new SR(Settings::class),
            new SR(ParcelIssues::class),
            new SR(LoggerInterface::class),
            $now,
        ],
    ],
    ReceptionPrint::class => [
        'class' => ReceptionPrint::class,
        'arguments' => [new SR(RozetkaClient::class), new SR(LoggerInterface::class)],
    ],
    RozetkaOrders::class => [
        'class' => RozetkaOrders::class,
        'arguments' => [new SR(EntityFactory::class), new SR(OrderDeliveryLines::class), new SR(OrderDeliveryStore::class)],
    ],
];
