<?php

namespace Symbiote\AdvancedWorkflow\Extensions;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;
use SilverStripe\Versioned\Versioned;
use Symbiote\AdvancedWorkflow\Forms\AWRequiredFields;
use Symbiote\AdvancedWorkflow\Jobs\WorkflowPublishTargetJob;
use Symbiote\AdvancedWorkflow\Services\WorkflowService;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJobService;

// Queued jobs descriptor is required for this extension
if (!class_exists(QueuedJobDescriptor::class)) {
    return;
}

/**
 * Adds embargo period and expiry dates to content items
 *
 * @author marcus@symbiote.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class WorkflowEmbargoExpiryExtension extends DataExtension
{
    private static $db = array(
        'DesiredPublishDate'    => 'DBDatetime',
        'DesiredUnPublishDate'  => 'DBDatetime',
        'PublishOnDate'         => 'DBDatetime',
        'UnPublishOnDate'       => 'DBDatetime',
        'AllowEmbargoedEditing' => 'Boolean',
    );

    private static $has_one = array(
        'PublishJob'            => QueuedJobDescriptor::class,
        'UnPublishJob'          => QueuedJobDescriptor::class,
    );

    private static $dependencies = array(
        'workflowService'       => '%$' . WorkflowService::class,
    );

    private static $defaults = array(
        'AllowEmbargoedEditing' => true
    );

    /**
     * @deprecated 5.2.0:6.0.0 This setting does nothing and will be removed in in 6.0
     * @var bool
     */
    public static $showTimePicker = true;

    /**
     * @var WorkflowService
     */
    protected $workflowService;

    /**
     * Is a workflow in effect?
     *
     * @var bool
     */
    public $isWorkflowInEffect = false;

    /**
     * A basic extended validation routine method return format
     *
     * @var array
     */
    public static $extendedMethodReturn = array(
        'fieldName'  => null,
        'fieldField' => null,
        'fieldMsg'   => null,
        'fieldValid' => true,
    );

    /**
     * @param FieldList $fields
     */
    public function updateCMSFields(FieldList $fields)
    {
        // requirements
        // ------------
        $module = 'symbiote/silverstripe-advancedworkflow';
        Requirements::add_i18n_javascript($module . ':client/lang');
        Requirements::css($module . ':client/dist/styles/advancedworkflow.css');
        Requirements::javascript($module . ':client/dist/js/advancedworkflow.js');

        // Fields
        // ------

        // we never show these explicitly in admin
        $fields->removeByName('PublishJobID');
        $fields->removeByName('UnPublishJobID');

        $this->setIsWorkflowInEffect();

        $fields->findOrMakeTab(
            'Root.PublishingSchedule',
            _t('WorkflowEmbargoExpiryExtension.TabTitle', 'Publishing Schedule')
        );
        if ($this->getIsWorkflowInEffect()) {
            // add fields we want in this context
            $fields->addFieldsToTab('Root.PublishingSchedule', array(
                HeaderField::create(
                    'PublishDateHeader',
                    _t('WorkflowEmbargoExpiryExtension.REQUESTED_PUBLISH_DATE_H3', 'Expiry and Embargo'),
                    3
                ),
                LiteralField::create('PublishDateIntro', $this->getIntroMessage('PublishDateIntro')),
                $dt = DatetimeField::create(
                    'DesiredPublishDate',
                    _t('WorkflowEmbargoExpiryExtension.REQUESTED_PUBLISH_DATE', 'Requested publish date')
                )->setRightTitle(
                    DBHTMLText::create()->setValue(_t(
                        'WorkflowEmbargoExpiryExtension.REQUESTED_PUBLISH_DATE_RIGHT_TITLE',
                        'To request this page to be <strong>published immediately</strong> '
                        . 'leave the date and time fields blank'
                    ))
                ),
                $ut = DatetimeField::create(
                    'DesiredUnPublishDate',
                    _t('WorkflowEmbargoExpiryExtension.REQUESTED_UNPUBLISH_DATE', 'Requested un-publish date')
                )->setRightTitle(
                    DBHTMLText::create()->setValue(_t(
                        'WorkflowEmbargoExpiryExtension.REQUESTED_UNPUBLISH_DATE_RIGHT_TITLE',
                        'To request this page to <strong>never expire</strong> leave the date and time fields blank'
                    ))
                ),
                DatetimeField::create(
                    'PublishOnDate',
                    _t('WorkflowEmbargoExpiryExtension.PUBLISH_ON', 'Scheduled publish date')
                )->setDisabled(true),
                DatetimeField::create(
                    'UnPublishOnDate',
                    _t('WorkflowEmbargoExpiryExtension.UNPUBLISH_ON', 'Scheduled un-publish date')
                )->setDisabled(true)
            ));
        } else {
            // remove fields that have been automatically added that we don't want
            $fields->removeByName('DesiredPublishDate');
            $fields->removeByName('DesiredUnPublishDate');

            // add fields we want in this context
            $fields->addFieldsToTab('Root.PublishingSchedule', array(
                HeaderField::create(
                    'PublishDateHeader',
                    _t('WorkflowEmbargoExpiryExtension.REQUESTED_PUBLISH_DATE_H3', 'Expiry and Embargo'),
                    3
                ),
                LiteralField::create('PublishDateIntro', $this->getIntroMessage('PublishDateIntro')),
                $dt = DatetimeField::create(
                    'PublishOnDate',
                    _t('WorkflowEmbargoExpiryExtension.PUBLISH_ON', 'Scheduled publish date')
                ),
                $ut = DatetimeField::create(
                    'UnPublishOnDate',
                    _t('WorkflowEmbargoExpiryExtension.UNPUBLISH_ON', 'Scheduled un-publish date')
                ),
            ));
        }
    }

    /**
     * Clears any existing publish job against this dataobject
     */
    public function clearPublishJob()
    {
        $job = $this->owner->PublishJob();
        if ($job && $job->exists()) {
            $job->delete();
        }
        $this->owner->PublishJobID = 0;
    }

    /**
     * Clears any existing unpublish job
     */
    public function clearUnPublishJob()
    {
        // Cancel any in-progress unpublish job
        $job = $this->owner->UnPublishJob();
        if ($job && $job->exists()) {
            $job->delete();
        }
        $this->owner->UnPublishJobID = 0;
    }

    /**
     * Ensure the existence of a publish job at the specified time
     *
     * @param int $when Timestamp to start this job, or null to start immediately
     */
    protected function ensurePublishJob($when)
    {
        // Check if there is a prior job
        if ($this->owner->PublishJobID) {
            $job = $this->owner->PublishJob();
            // Use timestamp for sake of comparison.
            if ($job && $job->exists() && DBDatetime::create()->setValue($job->StartAfter)->getTimestamp() == $when) {
                return;
            }
            $this->clearPublishJob();
        }

        // Create a new job with the specified schedule
        $job = new WorkflowPublishTargetJob($this->owner, 'publish');
        $this->owner->PublishJobID = Injector::inst()->get(QueuedJobService::class)
                ->queueJob($job, $when ? date('Y-m-d H:i:s', $when) : null);
    }

    /**
     * Ensure the existence of an unpublish job at the specified time
     *
     * @param int $when Timestamp to start this job, or null to start immediately
     */
    protected function ensureUnPublishJob($when)
    {
        // Check if there is a prior job
        if ($this->owner->UnPublishJobID) {
            $job = $this->owner->UnPublishJob();
            // Use timestamp for sake of comparison.
            if ($job && $job->exists() && DBDatetime::create()->setValue($job->StartAfter)->getTimestamp() == $when) {
                return;
            }
            $this->clearUnPublishJob();
        }

        // Create a new job with the specified schedule
        $job = new WorkflowPublishTargetJob($this->owner, 'unpublish');
        $this->owner->UnPublishJobID = Injector::inst()->get(QueuedJobService::class)
            ->queueJob($job, $when ? date('Y-m-d H:i:s', $when) : null);
    }

    public function onBeforeDuplicate($original, $doWrite)
    {
        $clone = $this->owner;

        $clone->PublishOnDate = null;
        $clone->UnPublishOnDate = null;
        $clone->clearPublishJob();
        $clone->clearUnPublishJob();
    }

    /**
     * {@see PublishItemWorkflowAction} for approval of requested publish dates
     */
    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        // only operate on staging content for this extension; otherwise, you
        // need to publish the page to be able to set a 'future' publish...
        // while the same could be said for the unpublish, the 'publish' state
        // is the one that must be avoided so we allow setting the 'unpublish'
        // date for as-yet-not-published content.
        if (Versioned::get_stage() === Versioned::LIVE) {
            return;
        }

        /*
         * Without checking if there's actually a workflow in effect, simply saving
         * as draft, would clear the Scheduled Publish & Unpublish date fields, which we obviously
         * don't want during a workflow: These date fields should be treated as a content
         * change that also requires approval (where such an approval step exists).
         *
         * - Check to see if we've got 'desired' publish/unpublish date(s).
         * - Check if there's a workflow attached to this content
         * - Reset values if it's safe to do so
         */
        if (!$this->getIsWorkflowInEffect()) {
            $resetPublishOnDate = $this->owner->DesiredPublishDate && $this->owner->PublishOnDate;
            if ($resetPublishOnDate) {
                $this->owner->PublishOnDate = null;
            }

            $resetUnPublishOnDate = $this->owner->DesiredUnPublishDate && $this->owner->UnPublishOnDate;
            if ($resetUnPublishOnDate) {
                $this->owner->UnPublishOnDate = null;
            }
        }

        // Jobs can only be queued for records that already exist
        if (!$this->owner->ID) {
            return;
        }
        
        if ($this->owner->hasMethod('isPublishJobRunning') && $this->owner->isPublishJobRunning()) {
            return;
        }

        // Check requested dates of publish / unpublish, and whether the page should have already been unpublished
        $now = DBDatetime::now()->getTimestamp();
        $publishTime = $this->owner->dbObject('PublishOnDate')->getTimestamp();
        $unPublishTime = $this->owner->dbObject('UnPublishOnDate')->getTimestamp();

        // We should have a publish job if:
        // if no unpublish or publish time, then the Workflow Publish Action will publish without a job
        if ((!$unPublishTime && $publishTime) // the unpublish date is not set
            || (
                // unpublish date has not passed
                $unPublishTime > $now
                // publish date not set or happens before unpublish date
                && ($publishTime && ($publishTime < $unPublishTime))
            )
        ) {
            // Trigger time immediately if passed
            $this->ensurePublishJob($publishTime < $now ? null : $publishTime);
        } else {
            $this->clearPublishJob();
        }

        // We should have an unpublish job if:
        if ($unPublishTime // we have an unpublish date
            &&
            $publishTime < $unPublishTime // publish date is before to unpublish date
        ) {
            // Trigger time immediately if passed
            $this->ensureUnPublishJob($unPublishTime < $now ? null : $unPublishTime);
        } else {
            $this->clearUnPublishJob();
        }
    }

    /**
     * Add badges to the site tree view to show that a page has been scheduled for publishing or unpublishing
     *
     * @param $flags
     */
    public function updateStatusFlags(&$flags)
    {
        $embargo = $this->getIsPublishScheduled();
        $expiry = $this->getIsUnPublishScheduled();

        if ($embargo || $expiry) {
            unset($flags['addedtodraft'], $flags['modified']);
        }

        if ($embargo && $expiry) {
            $flags['embargo_expiry'] = array(
                'text' => _t('WorkflowEmbargoExpiryExtension.BADGE_PUBLISH_UNPUBLISH', 'Embargo+Expiry'),
                'title' => sprintf(
                    '%s: %s, %s: %s',
                    _t('WorkflowEmbargoExpiryExtension.PUBLISH_ON', 'Scheduled publish date'),
                    $this->owner->PublishOnDate,
                    _t('WorkflowEmbargoExpiryExtension.UNPUBLISH_ON', 'Scheduled un-publish date'),
                    $this->owner->UnPublishOnDate
                ),
            );
        } elseif ($embargo) {
            $flags['embargo'] = array(
                'text' => _t('WorkflowEmbargoExpiryExtension.BADGE_PUBLISH', 'Embargo'),
                'title' => sprintf(
                    '%s: %s',
                    _t('WorkflowEmbargoExpiryExtension.PUBLISH_ON', 'Scheduled publish date'),
                    $this->owner->PublishOnDate
                ),
            );
        } elseif ($expiry) {
            $flags['expiry'] = array(
                'text' => _t('WorkflowEmbargoExpiryExtension.BADGE_UNPUBLISH', 'Expiry'),
                'title' => sprintf(
                    '%s: %s',
                    _t('WorkflowEmbargoExpiryExtension.UNPUBLISH_ON', 'Scheduled un-publish date'),
                    $this->owner->UnPublishOnDate
                ),
            );
        }
    }

    /*
     * Define an array of message-parts for use by {@link getIntroMessage()}
     *
     * @param string $key
     * @return array
     */
    public function getIntroMessageParts($key)
    {
        $parts = array(
            'PublishDateIntro' => array(
                'INTRO'=>_t(
                    'WorkflowEmbargoExpiryExtension.REQUESTED_PUBLISH_DATE_INTRO',
                    'Enter a date and/or time to specify embargo and expiry dates.'
                ),
                'BULLET_1'=>_t(
                    'WorkflowEmbargoExpiryExtension.REQUESTED_PUBLISH_DATE_INTRO_BULLET_1',
                    'These settings won\'t take effect until any approval actions are run'
                ),
                'BULLET_2'=>_t(
                    'WorkflowEmbargoExpiryExtension.REQUESTED_PUBLISH_DATE_INTRO_BULLET_2',
                    'If an embargo is already set, adding a new one prior to that date\'s passing will overwrite it'
                )
            )
        );
        // If there's no effective workflow, no need for the first bullet-point
        if (!$this->getIsWorkflowInEffect()) {
            $parts['PublishDateIntro']['BULLET_1'] = false;
        }
        return $parts[$key];
    }

    /*
     * Display some messages to the user, a little more complex that a simple one-liner
     *
     * @param string $key
     * @return string
     */
    public function getIntroMessage($key)
    {
        $msg = $this->getIntroMessageParts($key);
        $curr = Controller::curr();
        $msg = $curr->customise($msg)->renderWith('Includes/embargoIntro');
        return $msg;
    }

    /*
     * Validate
     */
    public function getCMSValidator()
    {
        $required = new AWRequiredFields();
        $required->setCaller($this);
        return $required;
    }

    /**
     * This is called in the AWRequiredFields class, this validates whether an Embargo and Expiry are not equal and that
     * Embargo is before Expiry, returning the appropriate message when it fails.
     *
     * @param $data
     * @return array
     */
    public function extendedRequiredFieldsEmbargoExpiry($data)
    {
        $response = array(
            'fieldName'  => 'DesiredUnPublishDate[date]',
            'fieldField' => null,
            'fieldMsg'   => null,
            'fieldValid' => true
        );

        if (isset($data['DesiredPublishDate'], $data['DesiredUnPublishDate'])) {
            $publish = DBDatetime::create()->setValue($data['DesiredPublishDate'])->getTimestamp();
            $unpublish = DBDatetime::create()->setValue($data['DesiredUnPublishDate'])->getTimestamp();

            // the times are the same
            if ($publish && $unpublish && $publish == $unpublish) {
                $response = array_merge($response, array(
                    'fieldMsg'   => _t(
                        'WorkflowEmbargoExpiryExtension.INVALIDSAMEEMBARGOEXPIRY',
                        'The publish date and unpublish date cannot be the same.'
                    ),
                    'fieldValid' => false
                ));
            } elseif ($publish && $unpublish && $publish > $unpublish) {
                $response = array_merge($response, array(
                    'fieldMsg'   => _t(
                        'WorkflowEmbargoExpiryExtension.INVALIDEXPIRY',
                        'The unpublish date cannot be before the publish date.'
                    ),
                    'fieldValid' => false
                ));
            }
        }

        return $response;
    }

    /**
     * Format a date according to member/user preferences
     *
     * @param string $date
     * @return string $date
     */
    public function getUserDate($date)
    {
        $date = DBDatetime::create()->setValue($date);
        $member = Security::getCurrentUser();
        return $date->FormatFromSettings($member);
    }

    /*
     * Sets property as boolean true|false if an effective workflow is found or not
     */
    public function setIsWorkflowInEffect()
    {
        // if there is a workflow applied, we can't set the publishing date directly, only the 'desired' publishing date
        $effective = $this->getWorkflowService()->getDefinitionFor($this->owner);
        $this->isWorkflowInEffect = $effective ? true : false;
    }

    public function getIsWorkflowInEffect()
    {
        return $this->isWorkflowInEffect;
    }

    /**
     * Returns whether a publishing date has been set and is after the current date
     *
     * @return bool
     */
    public function getIsPublishScheduled()
    {
        if (!$this->owner->PublishOnDate) {
            return false;
        }
        $now = DBDatetime::now()->getTimestamp();
        $publish = $this->owner->dbObject('PublishOnDate')->getTimestamp();

        return $now < $publish;
    }

    /**
     * Returns whether an unpublishing date has been set and is after the current date
     *
     * @return bool
     */
    public function getIsUnPublishScheduled()
    {
        if (!$this->owner->UnPublishOnDate) {
            return false;
        }
        $now = DBDatetime::now()->getTimestamp();
        $unpublish = $this->owner->dbObject('UnPublishOnDate')->getTimestamp();

        return $now < $unpublish;
    }

    /**
     * Add edit check for when publishing has been scheduled and if any workflow definitions want the item to be
     * disabled.
     *
     * @param Member $member
     * @return bool|null
     */
    public function canEdit($member)
    {
        if (!Permission::check('EDIT_EMBARGOED_WORKFLOW') && // not given global/override permission to edit
            !$this->owner->AllowEmbargoedEditing) { // item flagged as not editable
            $publishTime = $this->owner->dbObject('PublishOnDate');

            if ($publishTime && $publishTime->InFuture() || // when scheduled publish date is in the future
                // when there isn't a publish date, but a Job is in place (publish immediately, but queued jobs is
                // waiting)
                (!$publishTime && $this->owner->PublishJobID != 0)
            ) {
                return false;
            }
        }
    }

    /**
     * Set the workflow service instance
     *
     * @param WorkflowService $workflowService
     * @return $this
     */
    public function setWorkflowService(WorkflowService $workflowService)
    {
        $this->workflowService = $workflowService;
        return $this;
    }

    /**
     * Get the workflow service instance
     *
     * @return WorkflowService
     */
    public function getWorkflowService()
    {
        return $this->workflowService;
    }
}
