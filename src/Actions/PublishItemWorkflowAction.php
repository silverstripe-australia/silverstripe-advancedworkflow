<?php

namespace Symbiote\AdvancedWorkflow\Actions;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;

/**
 * Publishes an item or approves it for publishing/un-publishing through queued jobs.
 *
 * @author     marcus@symbiote.com.au
 * @license    BSD License (http://silverstripe.org/bsd-license/)
 * @package    advancedworkflow
 * @subpackage actions
 * @property int $PublishDelay
 */
class PublishItemWorkflowAction extends WorkflowAction
{
    /**
     * @var array
     */
    private static $db = array(
        'PublishDelay' => 'Int',
    );

    /**
     * @var string
     */
    private static $icon = 'symbiote/silverstripe-advancedworkflow:images/publish.png';

    /**
     * @var string
     */
    private static $table_name = 'PublishItemWorkflowAction';

    /**
     * @param WorkflowInstance $workflow
     * @return bool
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function execute(WorkflowInstance $workflow)
    {
        if (!$target = $workflow->getTarget()) {
            return true;
        }

        if ($this->targetHasEmbargoExpiryOrDelay($target)) {
            $this->queueEmbargoExpiryJobs($target);

            $target->write();
        } else {
            if ($target->hasMethod('publishRecursive')) {
                /** @var DataObject|Versioned $target */
                $target->publishRecursive();
            }
        }

        return true;
    }

    /**
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        if (class_exists(EmbargoExpiryExtension::class)) {
            $fields->addFieldToTab(
                'Root.Main',
                NumericField::create(
                    'PublishDelay',
                    _t('PublishItemWorkflowAction.PUBLICATIONDELAY', 'Publication Delay')
                )->setDescription(_t(
                    __CLASS__ . '.PublicationDelayDescription',
                    'Delay publiation by the specified number of days'
                ))
            );
        }

        return $fields;
    }

    /**
     * Publish action allows a user who is currently assigned at this point of the workflow to
     *
     * @param  DataObject $target
     * @return bool
     */
    public function canPublishTarget(DataObject $target)
    {
        return true;
    }

    /**
     * @param DataObject|EmbargoExpiryExtension $target
     * @return bool
     */
    public function targetHasEmbargoExpiryOrDelay($target)
    {
        if (!$this->targetHasEmbargoExpiryModules($target)) {
            return false;
        }

        if ($target->getDesiredPublishDateAsTimestamp() > 0
            || $target->getDesiredUnPublishDateAsTimestamp() > 0
        ) {
            return true;
        }

        if ($this->actionHasPublishDelayForTarget($target)) {
            return true;
        }

        return false;
    }

    /**
     * @param DataObject $target
     * @return bool
     */
    public function actionHasPublishDelayForTarget($target)
    {
        if (!$this->targetHasEmbargoExpiryModules($target)) {
            return false;
        }

        if (!$this->PublishDelay) {
            return false;
        }

        return true;
    }

    /**
     * @param DataObject|EmbargoExpiryExtension $target
     */
    public function queueEmbargoExpiryJobs($target)
    {
        // Queue UnPublishJob if it's required.
        if ($target->getDesiredUnPublishDateAsTimestamp() !== 0) {
            $target->createOrUpdateUnPublishJob($target->getDesiredUnPublishDateAsTimestamp());
        }

        // Queue PublishJob if it's required, and if it is, exit early (so that we don't queue by PublishDelay).
        if ($target->getDesiredPublishDateAsTimestamp() !== 0) {
            $target->createOrUpdatePublishJob($target->getDesiredPublishDateAsTimestamp());

            return;
        }

        // There was no requested PublishOnDate, so if this action has a PublishDelay, we'll use that.
        if ($this->actionHasPublishDelayForTarget($target)) {
            $target->createOrUpdatePublishJob(strtotime("+{$this->PublishDelay} days"));
        }
    }
}
