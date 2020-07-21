<?php

namespace Symbiote\AdvancedWorkflow\Actions;

use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\LabelField;
use SilverStripe\Forms\NumericField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\Versioned;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;

/**
 * Unpublishes an item or approves it for publishing/un-publishing through queued jobs.
 *
 * @author     marcus@symbiote.com.au
 * @license    BSD License (http://silverstripe.org/bsd-license/)
 * @package    advancedworkflow
 * @subpackage actions
 * @property int $UnpublishDelay
 */
class UnpublishItemWorkflowAction extends WorkflowAction
{
    /**
     * @var array
     */
    private static $db = array(
        'UnpublishDelay' => 'Int',
    );

    /**
     * @var string
     */
    private static $icon = 'symbiote/silverstripe-advancedworkflow:images/unpublish.png';

    /**
     * @var string
     */
    private static $table_name = 'UnpublishItemWorkflowAction';

    /**
     * @param WorkflowInstance $workflow
     * @return bool
     * @throws ValidationException
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
            if ($target->hasMethod('doUnpublish')) {
                /** @var DataObject|Versioned $target */
                $target->doUnpublish();
            }
        }

        return true;
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        if (class_exists(EmbargoExpiryExtension::class)) {
            $before = _t('UnpublishItemWorkflowAction.DELAYUNPUBDAYSBEFORE', 'Delay unpublishing by ');
            $after  = _t('UnpublishItemWorkflowAction.DELAYUNPUBDAYSAFTER', ' days');

            $fields->addFieldToTab('Root.Main', new FieldGroup(
                _t('UnpublishItemWorkflowAction.UNPUBLICATIONDELAY', 'Delay Un-publishing'),
                new LabelField('UnpublishDelayBefore', $before),
                new NumericField('UnpublishDelay', ''),
                new LabelField('UnpublishDelayAfter', $after)
            ));
        }

        return $fields;
    }

    /**
     * @param  DataObject $target
     * @return bool
     */
    public function canPublishTarget(DataObject $target)
    {
        return false;
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
            || $target->getDesiredUnPublishDateAsTimestamp() === 0
        ) {
            return true;
        }

        if ($this->actionHasUnPublishDelayForTarget($target)) {
            return true;
        }

        return false;
    }

    /**
     * @param DataObject $target
     * @return bool
     */
    public function actionHasUnPublishDelayForTarget($target)
    {
        if (!$this->targetHasEmbargoExpiryModules($target)) {
            return false;
        }

        if (!$this->UnpublishDelay) {
            return false;
        }

        return true;
    }

    /**
     * @param DataObject|EmbargoExpiryExtension $target
     */
    public function queueEmbargoExpiryJobs($target)
    {

        // Queue PublishJob if it's required.
        if ($target->getDesiredPublishDateAsTimestamp() !== 0) {
            $target->createOrUpdatePublishJob($target->getDesiredPublishDateAsTimestamp());
        }

        // Queue UnPublishJob if it's required, and if it is, exit early (so that we don't queue by UnpublishDelay).
        if ($target->getDesiredUnPublishDateAsTimestamp() !== 0) {
            $target->createOrUpdateUnPublishJob($target->getDesiredUnPublishDateAsTimestamp());

            return;
        }

        // There was no requested PublishOnDate, so if this action has a PublishDelay, we'll use that.
        if ($this->actionHasUnPublishDelayForTarget($target)) {
            $target->createOrUpdateUnPublishJob(strtotime("+{$this->UnpublishDelay} days"));
        }
    }
}
