<?php

namespace Symbiote\AdvancedWorkflow\Extensions;

use SilverStripe\Core\Extensible;
use SilverStripe\ORM\DataExtension;
use Symbiote\AdvancedWorkflow\Services\WorkflowService;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;

/**
 * Adds support for Workflow with the Embargo & Expiry module.
 *
 * @author marcus@symbiote.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class WorkflowEmbargoExpiryExtension extends DataExtension
{
    /**
     * @var array
     */
    private static $dependencies = array(
        'workflowService'       => '%$' . WorkflowService::class,
    );

    /**
     * @var WorkflowService
     */
    protected $workflowService;

    /**
     * @return bool
     * @see EmbargoExpiryExtension::objectRequiresPublishJob()
     */
    public function publishJobCanBeQueued()
    {
        return $this->canAllowEmbargoExpiryToQueueJobs();
    }

    /**
     * @return bool
     * @see EmbargoExpiryExtension::objectRequiresUnPublishJob()
     */
    public function unPublishJobCanBeQueued()
    {
        return $this->canAllowEmbargoExpiryToQueueJobs();
    }

    /**
     * When a workflow is in effect, we don't want the Embargo & Expiry module to create Publish/UnPublish Jobs at the
     * time when the record saves. Instead, these changes should go through the review process (just like any other
     * type of change). When the changes have been approved, we will pick up where we left off and trigger the queueing
     * of these Jobs.
     *
     * @return bool
     */
    public function canAllowEmbargoExpiryToQueueJobs()
    {
        // Sanity check. $owner is not WorkflowApplicable, so let EmbargoExpiryExtension do it's thing.
        if (!Extensible::has_extension($this->owner->ClassName, WorkflowApplicable::class)) {
            return true;
        }

        $definitions = $this->getWorkflowService()->getDefinitionsFor($this->owner);

        // No definitions have been set for $owner, so let EmbargoExpiryExtension do it's thing.
        if (!$definitions) {
            return true;
        }

        // Stop ExpiryExpiryExtension from queueing the Jobs for us. We'll handle this later ourselves as part of an
        // action.
        return false;
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
