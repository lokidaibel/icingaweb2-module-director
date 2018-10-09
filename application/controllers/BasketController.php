<?php

namespace Icinga\Module\Director\Controllers;

use dipl\Html\Link;
use dipl\Web\Widget\NameValueTable;
use Exception;
use Icinga\Date\DateFormatter;
use Icinga\Module\Director\ConfigDiff;
use Icinga\Module\Director\Core\Json;
use Icinga\Module\Director\Db;
use Icinga\Module\Director\DirectorObject\Automation\Basket;
use Icinga\Module\Director\DirectorObject\Automation\BasketSnapshot;
use Icinga\Module\Director\Forms\BasketCreateSnapshotForm;
use Icinga\Module\Director\Forms\BasketForm;
use Icinga\Module\Director\Forms\RestoreBasketForm;
use Icinga\Module\Director\Web\Controller\ActionController;
use dipl\Html\Html;
use Icinga\Module\Director\Web\Table\BasketSnapshotTable;

class BasketController extends ActionController
{
    protected $isApified = true;

    protected function basketTabs()
    {
        $name = $this->params->get('name');
        return $this->tabs()->add('show', [
            'label' => $this->translate('Basket'),
            'url'   => 'director/basket',
            'urlParams' => ['name' => $name]
        ])->add('snapshots', [
            'label' => $this->translate('Snapshots'),
            'url' => 'director/basket/snapshots',
            'urlParams' => ['name' => $name]
        ]);
    }

    /**
     * @throws \Icinga\Exception\NotFoundError
     */
    public function indexAction()
    {
        $this->actions()->add(
            Link::create(
                $this->translate('Back'),
                'director/baskets',
                null,
                ['class' => 'icon-left-big']
            )
        );
        $basket = $this->requireBasket();
        $this->basketTabs()->activate('show');
        $this->addTitle($basket->get('basket_name'));
        if ($basket->isEmpty()) {
            $this->content()->add(Html::tag('p', [
                'class' => 'information'
            ], $this->translate('This basket is empty')));
        }
        $this->content()->add(
            (new BasketForm())->setObject($basket)->handleRequest()
        );
    }

    public function createAction()
    {
        $this->actions()->add(
            Link::create(
                $this->translate('back'),
                'director/baskets',
                null,
                ['class' => 'icon-left-big']
            )
        );
        $this->addSingleTab($this->translate('Create Basket'));
        $this->addTitle($this->translate('Create a new Configuration Basket'));
        $form = (new BasketForm())
            ->setDb($this->db())
            ->handleRequest();
        $this->content()->add($form);
    }

    /**
     * @throws \Icinga\Exception\NotFoundError
     */
    public function snapshotsAction()
    {
        $name = $this->params->get('name');
        if ($name === null || $name === '') {
            $basket = null;
        } else {
            $basket = Basket::load($name, $this->db());
        }
        if ($basket === null) {
            $this->addTitle($this->translate('Basket Snapshots'));
            $this->addSingleTab($this->translate('Snapshots'));
        } else {
            $this->addTitle(sprintf(
                $this->translate('%: Snapshots'),
                $basket->get('basket_name')
            ));
            $this->basketTabs()->activate('snapshots');
        }
        if ($basket !== null) {
            $this->content()->add(
                (new BasketCreateSnapshotForm())
                    ->setBasket($basket)
                    ->handleRequest()
            );
        }
        $table = new BasketSnapshotTable($this->db());
        if ($basket !== null) {
            $table->setBasket($basket);
        }

        $table->renderTo($this);
    }

    /**
     * @throws \Icinga\Exception\MissingParameterException
     * @throws \Icinga\Exception\NotFoundError
     */
    public function snapshotAction()
    {
        $basket = $this->requireBasket();
        $snapshot = BasketSnapshot::load([
            'basket_uuid' => $basket->get('uuid'),
            'ts_create'   => $this->params->getRequired('ts'),
        ], $this->db());
        $snapSum = bin2hex($snapshot->get('content_checksum'));

        $this->addTitle(
            $this->translate('%s: %s (Snapshot)'),
            $basket->get('basket_name'),
            substr($snapSum, 0, 7)
        );

        $this->actions()->add([
            Link::create(
                $this->translate('Show Basket'),
                'director/basket',
                ['name' => $basket->get('basket_name')],
                ['data-base-target' => '_next']
            ),
            Link::create(
                $this->translate('Restore'),
                $this->url()->with('action', 'restore'),
                null,
                ['class' => 'icon-rewind']
            )
        ]);

        $properties = new NameValueTable();
        $properties->addNameValuePairs([
            $this->translate('Created') => DateFormatter::formatDateTime($snapshot->get('ts_create') / 1000),
            $this->translate('Content Checksum') => bin2hex($snapshot->get('content_checksum')),
        ]);
        $this->content()->add($properties);

        if ($this->params->get('action') === 'restore') {
            $form = new RestoreBasketForm();
            $form
                ->setSnapshot($snapshot)
                ->handleRequest();
            $this->content()->add($form);
            $targetDbName = $form->getValue('target_db');
            $connection = $form->getDb();
        } else {
            $targetDbName = null;
            $connection = $this->db();
        }

        $json = $snapshot->getJsonDump();
        $this->addSingleTab($this->translate('Snapshot'));
        $all = Json::decode($json);
        foreach ($all as $type => $objects) {
            if ($type === 'Datafield') {
                // $this->content()->add(Html::tag('h2', sprintf('+%d Datafield(s)', count($objects))));
                continue;
            }
            $table = new NameValueTable();
            $table->setAttribute('data-base-target', '_next');
            foreach ($objects as $key => $object) {
                $linkParams = [
                    'name'     => $basket->get('basket_name'),
                    'checksum' => $this->params->get('checksum'),
                    'ts'       => $this->params->get('ts'),
                    'type'     => $type,
                    'key'      => $key,
                ];
                if ($targetDbName !== null) {
                    $linkParams['target_db'] = $targetDbName;
                }
                try {
                    $current = BasketSnapshot::instanceByIdentifier($type, $key, $connection);
                    if ($current === null) {
                        $table->addNameValueRow(
                            $key,
                            Link::create(
                                Html::tag('strong', ['style' => 'color: green'], $this->translate('new')),
                                'director/basket/snapshotobject',
                                $linkParams
                            )
                        );
                        continue;
                    }
                    $hasChanged = Json::encode($current->export()) !== Json::encode($object);
                    $table->addNameValueRow(
                        $key,
                        $hasChanged
                        ? Link::create(
                            Html::tag('strong', ['style' => 'color: orange'], $this->translate('modified')),
                            'director/basket/snapshotobject',
                            $linkParams
                        )
                        : Html::tag('span', ['style' => 'color: green'], $this->translate('unchanged'))
                    );
                } catch (Exception $e) {
                    $table->addNameValueRow(
                        $key,
                        $e->getMessage()
                    );
                }
            }
            $this->content()->add(Html::tag('h2', $type));
            $this->content()->add($table);
        }
        $this->content()->add(Html::tag('div', ['style' => 'height: 5em']));
    }

    /**
     * @throws \Icinga\Exception\MissingParameterException
     * @throws \Icinga\Exception\NotFoundError
     */
    public function snapshotobjectAction()
    {
        $basket = $this->requireBasket();
        $snapshot = BasketSnapshot::load([
            'basket_uuid' => $basket->get('uuid'),
            'ts_create'   => $this->params->getRequired('ts'),
        ], $this->db());
        $snapshotUrl = $this->url()->without('type')->without('key')->setPath('director/basket/snapshot');
        $type = $this->params->get('type');
        $key = $this->params->get('key');

        $this->addTitle($this->translate('Single Object Diff'));
        $this->content()->add(Html::tag('p', [
            'class' => 'information'
        ], Html::sprintf(
            $this->translate('Comparing %s "%s" from Snapshot "%s" to current config'),
            $type,
            $key,
            Link::create(
                substr(bin2hex( $snapshot->get('content_checksum')), 0, 7),
                $snapshotUrl,
                null,
                ['data-base-target' => '_next']
            )
        )));
        $this->actions()->add([
            Link::create(
                $this->translate('back'),
                $snapshotUrl,
                null,
                ['class' => 'icon-left-big']
            ),
            Link::create(
                $this->translate('Restore'),
                $this->url()->with('action', 'restore'),
                null,
                ['class' => 'icon-rewind']
            )
        ]);

        $json = $snapshot->getJsonDump();
        $this->addSingleTab($this->translate('Snapshot'));
        $objects = Json::decode($json);
        $targetDbName = $this->params->get('target_db');
        if ($targetDbName === null) {
            $connection = $this->db();
        } else {
            $connection = Db::fromResourceName($targetDbName);
        }
        $objectFromBasket = $objects->$type->$key;
        $current = BasketSnapshot::instanceByIdentifier($type, $key, $connection);
        if ($current === null) {
            $current = '';
        } else {
            $current = Json::encode($current->export(), JSON_PRETTY_PRINT);
        }

        $this->content()->add(
            ConfigDiff::create(
                $current,
                Json::encode($objectFromBasket, JSON_PRETTY_PRINT)
            )->setHtmlRenderer('Inline')
        );
    }

    protected function requireBasket()
    {
        return Basket::load($this->params->getRequired('name'), $this->db());
    }
}
