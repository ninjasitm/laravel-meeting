<?php

namespace Nncodes\Meeting\Tests;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Nncodes\Meeting\Concerns\HostsMeetings;
use Nncodes\Meeting\Concerns\JoinsMeetings;
use Nncodes\Meeting\Concerns\PresentsMeetings;
use Nncodes\Meeting\Concerns\SchedulesMeetings;
use Nncodes\Meeting\Contracts\Host;
use Nncodes\Meeting\Contracts\Participant as ParticipantContract;
use Nncodes\Meeting\Contracts\Presenter;
use Nncodes\Meeting\Contracts\Provider;
use Nncodes\Meeting\Contracts\Scheduler;
use Nncodes\Meeting\Events\MeetingScheduled;
use Nncodes\Meeting\Events\MeetingCanceled;
use Nncodes\Meeting\Events\MeetingUpdated;
use Nncodes\Meeting\Events\ParticipantAdded;
use Nncodes\Meeting\Events\ParticipationCanceled;
use Nncodes\Meeting\MeetingAdder;
use Nncodes\Meeting\Models\Meeting;
use Nncodes\Meeting\Models\MeetingRoom;
use Nncodes\Meeting\Models\Participant;
use Nncodes\Meeting\Providers\Zoom\Sdk\Resources\Meeting as ZoomMeeting;
use Nncodes\Meeting\Providers\Zoom\Sdk\Resources\MeetingParticipant;
use Nncodes\Meeting\Providers\Zoom\Sdk\Zoom;
use Nncodes\Meeting\Providers\Zoom\ZoomProvider;

class MeetingCharacterizationTest extends TestCase
{
    public function test_it_registers_the_default_zoom_provider_and_preserves_publishable_schema(): void
    {
        $this->assertSame('zoom', config('meeting.default'));
        $this->assertInstanceOf(\Nncodes\Meeting\Providers\Zoom\ZoomProvider::class, app('laravel-meeting:zoom'));
        $this->assertTrue(Schema::hasColumns('meetings', ['uuid', 'topic', 'start_time', 'duration', 'provider', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('meeting_participants', ['uuid', 'participant_id', 'participant_type', 'meeting_id', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('meeting_rooms', ['uuid', 'name', 'email', 'type', 'group', 'deleted_at']));
        $this->assertContains(config_path('meeting.php'), ServiceProvider::pathsToPublish(\Nncodes\Meeting\MeetingServiceProvider::class, 'config'));
        $this->assertNotEmpty(array_filter(ServiceProvider::pathsToPublish(\Nncodes\Meeting\MeetingServiceProvider::class, 'migrations'), function ($path) {
            return strpos($path, 'create_meetings_table.php') !== false;
        }));
    }

    public function test_it_preserves_model_tables_casts_soft_deletes_and_relations(): void
    {
        $meeting = new Meeting;
        $participant = new Participant;

        $this->assertSame('meetings', $meeting->getTable());
        $this->assertSame('meeting_rooms', (new MeetingRoom)->getTable());
        $this->assertSame('meeting_participants', $participant->getTable());
        $this->assertSame('datetime:Y-m-d\\TH:i:se', $meeting->getCasts()['start_time']);
        $this->assertSame('datetime:Y-m-d\\TH:i:se', $participant->getCasts()['started_at']);
        $this->assertSame('deleted_at', $meeting->getDeletedAtColumn());
        $this->assertInstanceOf(MorphTo::class, $meeting->scheduler());
        $this->assertInstanceOf(MorphTo::class, $meeting->presenter());
        $this->assertInstanceOf(MorphTo::class, $meeting->host());
        $this->assertInstanceOf(MorphToMany::class, $meeting->participants(TestParticipant::class));
        $this->assertSame('uuid', $participant->getKeyName());
    }

    public function test_it_saves_meeting_metadata_and_lifecycle_payloads_without_http(): void
    {
        Event::fake([MeetingScheduled::class, MeetingUpdated::class, MeetingCanceled::class]);
        $this->bindFakeProvider();
        $this->createActorTables();
        $scheduler = TestScheduler::create();
        $presenter = TestPresenter::create();
        $host = TestHost::create();

        $meeting = Meeting::schedule('fake')
            ->withTopic('Characterized')
            ->startingAt(Carbon::now()->addHour())
            ->during(30)
            ->scheduledBy($scheduler)
            ->presentedBy($presenter)
            ->hostedBy($host)
            ->withMetaAttributes(['zoom_id' => 42])
            ->save();

        $this->assertSame('fake', $meeting->provider);
        $this->assertSame(42, $meeting->getMetaValue('zoom_id'));
        $this->assertTrue($meeting->scheduler->is($scheduler));
        $this->assertTrue($meeting->presenter->is($presenter));
        $this->assertTrue($meeting->host->is($host));
        Event::assertDispatched(MeetingScheduled::class, fn ($event) => $event->meeting->is($meeting));

        $meeting->updateTopic('Updated')->save();
        Event::assertDispatched(MeetingUpdated::class, fn ($event) => $event->meeting->is($meeting));

        $this->assertTrue($meeting->cancel());
        Event::assertDispatched(MeetingCanceled::class, fn ($event) => $event->meeting->is($meeting));
    }

    public function test_it_preserves_participant_metadata_and_cancellation_events_without_http(): void
    {
        Event::fake([ParticipantAdded::class, ParticipationCanceled::class]);
        $this->bindFakeProvider();
        $this->createActorTables();
        $meeting = $this->newMeeting();
        $participant = TestParticipant::create(['email' => 'participant@example.test']);

        $pivot = $meeting->addParticipant($participant);

        $this->assertSame('registrant-1', $pivot->getMetaValue('registrantId'));
        $this->assertSame('https://zoom.test/join', $pivot->getMetaValue('joinUrl'));
        $this->assertSame('participant@example.test', $pivot->getMetaValue('email'));
        Event::assertDispatched(ParticipantAdded::class, fn ($event) => $event->participant->is($pivot));

        $this->assertTrue($meeting->cancelParticipation($participant));
        $this->assertNull($pivot->fresh()->getMetaValue('registrantId'));
        Event::assertDispatched(ParticipationCanceled::class, fn ($event) => $event->participant->is($pivot));
    }

    public function test_cancellation_hides_the_pivot_and_reregistration_restores_it(): void
    {
        Event::fake([ParticipantAdded::class, ParticipationCanceled::class]);
        $this->bindFakeProvider();
        $this->createActorTables();
        $meeting = $this->newMeeting();
        $participant = TestParticipant::create(['email' => 'participant@example.test']);
        $first = $meeting->addParticipant($participant);

        $meeting->cancelParticipation($participant);

        $this->assertFalse($meeting->hasParticipant($participant));
        $this->assertNull($meeting->participant($participant));
        $this->assertCount(0, $meeting->participants(TestParticipant::class)->get());
        $this->assertCount(0, $participant->meetings()->get());

        $restored = $meeting->addParticipant($participant);

        $this->assertSame($first->getKey(), $restored->getKey());
        $this->assertTrue($meeting->hasParticipant($participant));
        $this->assertCount(1, $meeting->participants(TestParticipant::class)->get());
        $this->assertCount(1, $participant->meetings()->get());
        $this->assertSame('registrant-1', $restored->getMetaValue('registrantId'));
        Event::assertDispatched(ParticipantAdded::class, 2);
        Event::assertDispatched(ParticipationCanceled::class, 1);
    }

    public function test_zoom_provider_uses_the_sdk_boundary_for_lifecycle_payloads_and_intentional_no_op_hooks(): void
    {
        Event::fake([MeetingScheduled::class, MeetingUpdated::class, MeetingCanceled::class, ParticipantAdded::class, ParticipationCanceled::class]);
        $api = new RecordingZoom;
        $this->app->instance('laravel-meeting:zoom', new ZoomProvider($api));
        config(['meeting.providers.zoom.share_rooms' => false, 'meeting.providers.zoom.meeting_settings' => ['waiting_room' => true], 'meeting.allow_concurrent_meetings' => ['host' => true, 'participant' => true, 'presenter' => true, 'scheduler' => true]]);
        $this->createActorTables();

        $meeting = Meeting::schedule('zoom')->withTopic('Zoom contract')->startingAt(Carbon::parse('2026-01-02 10:00:00', 'UTC'))->during(30)
            ->scheduledBy(TestScheduler::create())->presentedBy(TestPresenter::create())->hostedBy(TestHost::create(['uuid' => 'host-1']))->save();
        $this->assertSame(7001, $meeting->getMetaValue('zoom_id'));
        $this->assertSame(['waiting_room' => true], $api->created[0]['data']['settings']);

        $meeting->updateTopic('Changed')->save();
        $this->assertSame(7001, $api->updated[0]['id']);
        $this->assertSame(['waiting_room' => true], $api->updated[0]['data']['settings']);

        $participant = TestParticipant::create(['email' => 'participant@example.test']);
        $pivot = $meeting->addParticipant($participant);
        $this->assertSame('registrant-1', $pivot->getMetaValue('registrantId'));
        $this->assertSame('https://zoom.test/join/1', $pivot->getMetaValue('joinUrl'));
        $this->assertSame('participant@example.test', $pivot->getMetaValue('email'));
        $meeting->joinParticipant($participant);
        $meeting->leaveParticipant($participant);
        $this->assertSame('registrant-1', $pivot->fresh()->getMetaValue('registrantId'));

        $meeting->cancelParticipation($participant);
        $this->assertSame(['action' => 'cancel', 'registrants' => [['id' => 'registrant-1', 'email' => 'participant@example.test']]], $api->participantStatuses[0]['data']);
        $this->assertNull($pivot->fresh()->getMetaValue('registrantId'));
        $meeting->start()->end();
        $this->assertTrue($meeting->cancel());
        $this->assertSame(7001, $api->deleted[0]);
        Event::assertDispatched(MeetingScheduled::class);
        Event::assertDispatched(MeetingUpdated::class);
        Event::assertDispatched(MeetingCanceled::class);
        Event::assertDispatched(ParticipantAdded::class);
        Event::assertDispatched(ParticipationCanceled::class);
    }

    public function test_changing_a_zoom_host_reregisters_active_participants_through_the_sdk_boundary(): void
    {
        $api = new RecordingZoom;
        $this->app->instance('laravel-meeting:zoom', new ZoomProvider($api));
        config(['meeting.providers.zoom.share_rooms' => false, 'meeting.allow_concurrent_meetings' => ['host' => true, 'participant' => true, 'presenter' => true, 'scheduler' => true]]);
        $this->createActorTables();

        $meeting = Meeting::schedule('zoom')->withTopic('Host change')->startingAt(Carbon::parse('2026-01-02 10:00:00', 'UTC'))->during(30)
            ->scheduledBy(TestScheduler::create())->presentedBy(TestPresenter::create())->hostedBy(TestHost::create(['uuid' => 'host-1']))->save();
        $participant = TestParticipant::create(['email' => 'participant@example.test']);
        $pivot = $meeting->addParticipant($participant);

        $meeting->updateHost(TestHost::create(['uuid' => 'host-2']))->save();

        $this->assertSame(['host-1', 'host-2'], array_column($api->created, 'userId'));
        $this->assertSame([7001], $api->deleted);
        $this->assertSame(['id' => 7002, 'data' => ['action' => 'cancel', 'registrants' => [['id' => 'registrant-1', 'email' => 'participant@example.test']]]], $api->participantStatuses[0]);
        $this->assertSame(['id' => 7002, 'data' => ['email' => 'participant@example.test', 'first_name' => 'Test', 'last_name' => 'Participant']], $api->participantsAdded[1]);
        $this->assertSame('registrant-2', $pivot->fresh()->getMetaValue('registrantId'));
        $this->assertSame('https://zoom.test/join/2', $pivot->fresh()->getMetaValue('joinUrl'));
        $this->assertSame('participant@example.test', $pivot->fresh()->getMetaValue('email'));
    }

    private function newMeeting(): Meeting
    {
        return Meeting::schedule('fake')->withTopic('Participants')->startingAt(Carbon::now()->addHour())->during(30)
            ->scheduledBy(TestScheduler::create())->presentedBy(TestPresenter::create())->hostedBy(TestHost::create())->save();
    }

    private function bindFakeProvider(): void
    {
        $this->app->bind('laravel-meeting:fake', FakeProvider::class);
        config([
            'meeting.providers.fake' => ['type' => FakeProvider::class],
            'meeting.allow_concurrent_meetings' => ['host' => true, 'participant' => true, 'presenter' => true, 'scheduler' => true],
        ]);
    }

    private function createActorTables(): void
    {
        Schema::dropIfExists('actors');
        Schema::dropIfExists('meeting_test_participants');
        Schema::create('actors', function ($table): void {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->nullableTimestamps();
        });
        Schema::create('meeting_test_participants', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->nullableTimestamps();
        });
    }
}

class TestScheduler extends Model implements Scheduler { use SchedulesMeetings; protected $table = 'actors'; public $timestamps = false; protected $guarded = []; }
class TestPresenter extends Model implements Presenter { use PresentsMeetings; protected $table = 'actors'; public $timestamps = false; protected $guarded = []; }
class TestHost extends Model implements Host { use HostsMeetings; protected $table = 'actors'; public $timestamps = false; protected $guarded = []; }
class TestParticipant extends Model implements ParticipantContract { use JoinsMeetings; protected $table = 'meeting_test_participants'; public $timestamps = false; protected $guarded = []; public function getParticipantEmailAddress(): string { return $this->email; } public function getParticipantFirstName(): string { return 'Test'; } public function getParticipantLastName(): string { return 'Participant'; } }

class FakeProvider implements Provider
{
    public function getFacadeAccessor(): string { return 'fake'; }
    public function scheduling(MeetingAdder $meeting): void {}
    public function scheduled(Meeting $meeting): void { event(new MeetingScheduled($meeting)); }
    public function updating(Meeting $meeting): void {}
    public function updated(Meeting $meeting): void { event(new MeetingUpdated($meeting)); }
    public function starting(Meeting $meeting): void {}
    public function started(Meeting $meeting): void {}
    public function ending(Meeting $meeting): void {}
    public function ended(Meeting $meeting): void {}
    public function canceling(Meeting $meeting): void {}
    public function canceled(Meeting $meeting): void { event(new MeetingCanceled($meeting)); }
    public function participantAdding(ParticipantContract $participant, Meeting $meeting, string $uuid): void { $meeting->setMeta($uuid)->asObject((object) ['registrantId' => 'registrant-1', 'joinUrl' => 'https://zoom.test/join']); }
    public function participantAdded(Participant $participant): void { $pending = $participant->meeting->getMeta($participant->uuid); $participant->setMeta('registrantId')->asString($pending->value->registrantId); $participant->setMeta('joinUrl')->asString($pending->value->joinUrl); $participant->setMeta('email')->asString($participant->participant->getParticipantEmailAddress()); $pending->delete(); event(new ParticipantAdded($participant)); }
    public function participationCanceling(Participant $participant): void { $participant->clearMetas(); }
    public function participationCanceled(Participant $participant): void { event(new ParticipationCanceled($participant)); }
    public function participantJoining(Participant $participant): void {}
    public function participantJoined(Participant $participant): void {}
    public function participantLeaving(Participant $participant): void {}
    public function participantLeft(Participant $participant): void {}
    public function getPresenterAccess(Meeting $meeting) { return null; }
    public function getParticipantAccess(Meeting $meeting, ParticipantContract $participant) { return null; }
}

class RecordingZoom extends Zoom
{
    public array $created = [];
    public array $updated = [];
    public array $deleted = [];
    public array $participantStatuses = [];
    public array $participantsAdded = [];

    public function __construct() {}
    public function createUserMeeting(string $userId, array $data): ZoomMeeting { $this->created[] = compact('userId', 'data'); return new ZoomMeeting(['id' => 7000 + count($this->created)], $this); }
    public function updateMeeting(int $meetingId, array $data, array $query = []): void { $this->updated[] = ['id' => $meetingId, 'data' => $data]; }
    public function deleteMeeting(int $meetingId, array $query = []): void { $this->deleted[] = $meetingId; }
    public function addMeetingParticipant(int $meetingId, array $data, array $query = []): MeetingParticipant { $this->participantsAdded[] = ['id' => $meetingId, 'data' => $data]; $number = count($this->participantsAdded); $registrant = new MeetingParticipant(['email' => $data['email']], $this); $registrant->registrantId = "registrant-{$number}"; $registrant->joinUrl = "https://zoom.test/join/{$number}"; return $registrant; }
    public function updateMeetingParticipantStatus(int $meetingId, array $data, array $query = []): void { $this->participantStatuses[] = ['id' => $meetingId, 'data' => $data]; }
}
