<?php

namespace Symbiote\AdvancedWorkflow\Tests;

use Exception;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Subsites\Extensions\SiteTreeSubsites;
use SilverStripe\Translatable\Model\Translatable;
use SilverStripe\Versioned\Versioned;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\Extensions\WorkflowApplicable;
use Symbiote\AdvancedWorkflow\Extensions\WorkflowEmbargoExpiryExtension;
use Symbiote\AdvancedWorkflow\Services\WorkflowService;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;

/**
 * @author marcus@symbiote.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class WorkflowEmbargoExpiryTest extends SapphireTest
{
    protected static $fixture_file = 'WorkflowEmbargoExpiry.yml';

    /**
     * @var array
     */
    protected static $required_extensions = array(
        SiteTree::class => array(
            WorkflowApplicable::class,
            EmbargoExpiryExtension::class,
            WorkflowEmbargoExpiryExtension::class,
        ),
    );

    /**
     * @var array
     */
    protected static $illegal_extensions = array(
        SiteTree::class => array(
            Translatable::class,
            SiteTreeSubsites::class,
        ),
    );

    /**
     * @throws Exception
     */
    protected function setUp()
    {
        DBDatetime::set_mock_now('2014-01-05 12:00:00');

        // This doesn't play nicely with PHPUnit
        Config::modify()->set(QueuedJobService::class, 'use_shutdown_function', false);

        parent::setUp();
    }

    protected function tearDown()
    {
        DBDatetime::clear_mock_now();

        parent::tearDown();
    }

    /**
     * Start a workflow for a page, this will set it into a state where a workflow is currently being processes
     *
     * @param DataObject|mixed $obj
     * @return mixed
     * @throws ValidationException
     */
    private function startWorkflow($obj)
    {
        $workflow = $this->objFromFixture(WorkflowDefinition::class, 'requestPublication');
        $obj->WorkflowDefinitionID = $workflow->ID;
        $obj->write();

        $svc = singleton(WorkflowService::class);
        $svc->startWorkflow($obj, $obj->WorkflowDefinitionID);
        return $obj;
    }

    /**
     * Start and finish a workflow which will publish the page immediately basically.
     *
     * @param DataObject|mixed $obj
     * @return DataObject
     * @throws ValidationException
     */
    private function finishWorkflow($obj)
    {
        $workflow = $this->objFromFixture(WorkflowDefinition::class, 'approvePublication');
        $obj->WorkflowDefinitionID = $workflow->ID;
        $obj->write();

        $svc = singleton(WorkflowService::class);
        $svc->startWorkflow($obj, $obj->WorkflowDefinitionID);

        $obj = DataObject::get($obj->ClassName)->byID($obj->ID);
        return $obj;
    }

    /**
     * Retrieves the live version for an object
     *
     * @param DataObject $obj
     * @return DataObject
     */
    private function getLive($obj)
    {
        $oldMode = Versioned::get_reading_mode();
        Versioned::set_reading_mode(Versioned::LIVE);
        $live = DataObject::get($obj->ClassName)->byID($obj->ID);
        Versioned::set_reading_mode($oldMode);

        return $live;
    }

    /**
     * Test when embargo and expiry are both empty.
     *
     * No jobs should be created, but page is published by the workflow action.
     *
     * @throws ValidationException
     */
    public function testEmptyEmbargoExpiry()
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'emptyEmbargoExpiry');
        $page->Content = 'Content to go live';

        // This record should not yet be published.
        $this->assertFalse($page->isPublished());
        $this->assertEquals(0, $page->PublishJobID);
        $this->assertEquals(0, $page->UnPublishJobID);

        $page = $this->finishWorkflow($page);

        /** @var SiteTree|EmbargoExpiryExtension $live */
        $live = $this->getLive($page);

        $this->assertNotEmpty($live->Content);
        $this->assertEquals(0, $page->PublishJobID);
        $this->assertEquals(0, $page->UnPublishJobID);
    }

    /**
     * Test when both embargo and expiry dates are set.
     *
     * Jobs should be created, and the page should not be published as part of the workflow action.
     *
     * @throws ValidationException
     */
    public function testProcessEmbargoExpiry()
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'processEmbargoExpiry');

        $page->Content = 'Content to go live';
        $page->DesiredPublishDate = '2014-01-06 12:00:00';
        $page->DesiredUnPublishDate = '2014-01-08 12:00:00';

        // Jobs would ordinarily be queued at this time, but because we have a Workflow applied (in the fixture), we
        // should halt that from happening.
        $page->write();

        $this->assertNotNull($page->DesiredPublishDate);
        $this->assertNotNull($page->DesiredUnPublishDate);
        $this->assertNull($page->PublishOnDate);
        $this->assertNull($page->UnPublishOnDate);
        $this->assertEquals(0, $page->PublishJobID);
        $this->assertEquals(0, $page->UnPublishJobID);

        $page = $this->finishWorkflow($page);

        // Check that the Jobs have been created, and that the object fields were correctly updated.
        $this->assertNull($page->DesiredPublishDate);
        $this->assertNull($page->DesiredUnPublishDate);
        $this->assertNotNull($page->PublishOnDate);
        $this->assertNotNull($page->UnPublishOnDate);
        $this->assertNotNull($page->PublishJob());
        $this->assertNotNull($page->UnPublishJob());

        // This record should not yet be published.
        $this->assertFalse($page->isPublished());
    }

    /**
     * Test when only an embargo date is set.
     *
     * A publish job should be created, and the page should not be published as part of the workflow action.
     *
     * No un-publish job should be created.
     *
     * @throws ValidationException
     */
    public function testProcessEmbargo()
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'processEmbargo');

        $page->Content = 'Content to go live';
        $page->DesiredPublishDate = '2014-01-06 12:00:00';

        // Jobs would ordinarily be queued at this time, but because we have a Workflow applied (in the fixture), we
        // should halt that from happening.
        $page->write();

        $this->assertNotNull($page->DesiredPublishDate);
        $this->assertNull($page->DesiredUnPublishDate);
        $this->assertNull($page->PublishOnDate);
        $this->assertNull($page->UnPublishOnDate);
        $this->assertEquals(0, $page->PublishJobID);
        $this->assertEquals(0, $page->UnPublishJobID);

        $page = $this->finishWorkflow($page);

        // Check that the Jobs have been created, and that the object fields were correctly updated.
        $this->assertNull($page->DesiredPublishDate);
        $this->assertNull($page->DesiredUnPublishDate);
        $this->assertNotNull($page->PublishOnDate);
        $this->assertNull($page->UnPublishOnDate);
        $this->assertTrue($page->PublishJob()->exists());
        $this->assertEquals(0, $page->UnPublishJobID);

        // This record should not yet be published.
        $this->assertFalse($page->isPublished());
    }

    /**
     * Test when only an expiry date is set.
     *
     * An un-publish job should be created, and the page should not be published as part of the workflow action.
     *
     * No publish job should be created.
     *
     * @throws ValidationException
     */
    public function testProcessExpiry()
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'processExpiry');

        $page->Content = 'Content to go live';
        $page->DesiredUnPublishDate = '2014-01-08 12:00:00';

        // Jobs would ordinarily be queued at this time, but because we have a Workflow applied (in the fixture), we
        // should halt that from happening.
        $page->write();

        $this->assertNull($page->DesiredPublishDate);
        $this->assertNotNull($page->DesiredUnPublishDate);
        $this->assertNull($page->PublishOnDate);
        $this->assertNull($page->UnPublishOnDate);
        $this->assertEquals(0, $page->PublishJobID);
        $this->assertEquals(0, $page->UnPublishJobID);

        $page = $this->finishWorkflow($page);

        // Check that the Jobs have been created, and that the object fields were correctly updated.
        $this->assertNull($page->DesiredPublishDate);
        $this->assertNull($page->DesiredUnPublishDate);
        $this->assertNull($page->PublishOnDate);
        $this->assertNotNull($page->UnPublishOnDate);
        $this->assertEquals(0, $page->PublishJobID);
        $this->assertTrue($page->UnPublishJob()->exists());

        // This record should not yet be published.
        $this->assertFalse($page->isPublished());
    }
}
