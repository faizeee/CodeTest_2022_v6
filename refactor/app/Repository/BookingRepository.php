<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

  protected $logger;

  /**
   * @param Job $model
   */
  function __construct(protected Job $model, protected MailerInterface $mailer)
  {
    parent::__construct($model);
    $this->mailer = $mailer;
    $this->logger = new Logger('admin_logger');
    $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
    $this->logger->pushHandler(new FirePHPHandler());
  }

  public function getUsersJobs(int $user_id)
  {
    $cuser = User::find($user_id);
    $usertype = '';
    $emergencyJobs = [];
    $normalJobs = [];

    if ($cuser) {
      if ($cuser->is('customer')) {
        $jobs = $this->getCustomerJobs($cuser);
        $usertype = 'customer';
      } elseif ($cuser->is('translator')) {
        $jobs = $this->getTranslatorJobs($cuser);
        $usertype = 'translator';
      }

      if ($jobs) {
        foreach ($jobs as $job_item) {
          $this->categorizeJob($job_item, $emergencyJobs, $normalJobs);
        }
        $normalJobs = $this->processNormalJobs($normalJobs, $user_id);
      }
    }

    return [
      'emergencyJobs' => $emergencyJobs,
      'normalJobs' => $normalJobs,
      'cuser' => $cuser,
      'usertype' => $usertype,
    ];
  }

  /**
   * @param User $cuser
   * @return mixed
   */
  private function getCustomerJobs(User $cuser)
  {
    return $cuser->jobs()
      ->with([
        'user.userMeta',
        'user.average',
        'translatorJobRel.user.average',
        'language',
        'feedback'
      ])
      ->whereIn('status', ['pending', 'assigned', 'started'])
      ->orderBy('due', 'asc')
      ->get();
  }

  /**
   * @param User $cuser
   * @return mixed
   */
  private function getTranslatorJobs(User $cuser)
  {
    return Job::getTranslatorJobs($cuser->id, 'new')->pluck('jobs')->all();
  }

  /**
   * @param $job_item
   * @param $emergencyJobs
   * @param $normalJobs
   * @return void
   */
  private function categorizeJob($job_item, &$emergencyJobs, &$normalJobs)
  {
    if ($job_item->immediate == 'yes') {
      $emergencyJobs[] = $job_item;
    } else {
      $normalJobs[] = $job_item;
    }
  }

  /**
   * @param array $normalJobs
   * @param int $user_id
   * @return mixed
   */
  private function processNormalJobs(array $normalJobs, int $user_id)
  {
    return collect($normalJobs)->each(function ($item) use ($user_id) {
      $item['usercheck'] = Job::checkParticularJob($user_id, $item);
    })->sortBy('due')->all();
  }

  public function getUsersJobsHistory(int $user_id, Request $request)
  {
    $pagenum = $request->get('page', 1);
    $cuser = User::find($user_id);

    if (!$cuser) {
      return [
        'emergencyJobs' => [],
        'normalJobs' => [],
        'jobs' => [],
        'cuser' => $cuser,
        'usertype' => '',
        'numpages' => 0,
        'pagenum' => $pagenum,
      ];
    }

    if ($cuser->is('customer')) {
      return $this->getCustomerJobsHistory($cuser);
    }

    return $this->getTranslatorJobsHistory($cuser, $pagenum);
  }

  private function getCustomerJobsHistory(User $cuser)
  {
    $jobs = $cuser->jobs()
      ->with([
        'user.userMeta',
        'user.average',
        'translatorJobRel.user.average',
        'language',
        'feedback',
        'distance'
      ])
      ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
      ->orderBy('due', 'desc')
      ->paginate(15);

    return [
      'emergencyJobs' => [],
      'normalJobs' => [],
      'jobs' => $jobs,
      'cuser' => $cuser,
      'usertype' => 'customer',
      'numpages' => 0,
      'pagenum' => 0,
    ];
  }

  private function getTranslatorJobsHistory(User $cuser, int $pagenum)
  {
    $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
    $totaljobs = $jobs_ids->total();
    $numpages = ceil($totaljobs / 15);

    return [
      'emergencyJobs' => [],
      'normalJobs' => $jobs_ids,
      'jobs' => $jobs_ids,
      'cuser' => $cuser,
      'usertype' => 'translator',
      'numpages' => $numpages,
      'pagenum' => $pagenum,
    ];
  }

  public function store($user, $data)
  {
    // Validate the incoming data
    $validator = $this->validateJobData($data);

    if ($validator->fails()) {
      return [
        'status' => 'fail',
        'message' => $validator->errors()->first(),
        'field_name' => $validator->errors()->keys()[0] ?? null,
      ];
    }

    // Process the job creation
    try {
      $response = $this->processJobCreation($user, $data);
    } catch (\Exception $e) {
      return [
        'status' => 'fail',
        'message' => $e->getMessage(),
      ];
    }

    return $response;
  }

  /**
   * Validate the job data.
   *
   * @param array $data
   * @return \Illuminate\Contracts\Validation\Validator
   */
  private function validateJobData($data)
  {
    return Validator::make($data, [
      'from_language_id' => 'required',
      'immediate' => 'required',
      'due_date' => Rule::requiredIf(function () use ($data) {
        return $data['immediate'] == 'no';
      }),
      'due_time' => Rule::requiredIf(function () use ($data) {
        return $data['immediate'] == 'no';
      }),
      'customer_phone_type' => 'required_without:customer_physical_type',
      'customer_physical_type' => 'required_without:customer_phone_type',
      'duration' => 'required',
    ], [
      'required' => 'Du måste fylla in alla fält',
      'required_without' => 'Du måste göra ett val här',
    ]);
  }

  /**
   * Process job creation.
   *
   * @param mixed $user
   * @param array $data
   * @return array
   * @throws \Exception
   */
  private function processJobCreation($user, $data)
  {

    if ($user->user_type != config('app.customer_role_id')) {
      throw new \Exception("Translator cannot create booking");
    }

    $immediatetime = 5;
    $consumer_type = $user->userMeta->consumer_type;
    $cuser = $user;

    // Set customer phone type
    $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';

    // Set customer physical type
    $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

    if ($data['immediate'] == 'yes') {
      $due_carbon = Carbon::now()->addMinute($immediatetime);
      $data['due'] = $due_carbon->format('Y-m-d H:i:s');
      $data['immediate'] = 'yes';
      $data['customer_phone_type'] = 'yes';
      $response['type'] = 'immediate';
    } else {
      $due = $data['due_date'] . " " . $data['due_time'];
      $response['type'] = 'regular';
      $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
      $data['due'] = $due_carbon->format('Y-m-d H:i:s');
      if ($due_carbon->isPast()) {
        throw new \Exception("Can't create booking in the past");
      }
    }

    // Set gender and certified fields
    $data = $this->setGenderAndCertified($data);

    // Set job type based on consumer type
    $data['job_type'] = $this->setJobType($consumer_type);

    // Set created at and will expire at
    $data['b_created_at'] = date('Y-m-d H:i:s');
    if (isset($due)) {
      $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
    }
    $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

    // Create the job
    $job = $cuser->jobs()->create($data);

    // Build the response
    $response['status'] = 'success';
    $response['id'] = $job->id;
    $data['job_for'] = [];

    if ($job->gender != null) {
      $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
    }

    if ($job->certified != null) {
      if ($job->certified == 'both') {
        $data['job_for'][] = 'normal';
        $data['job_for'][] = 'certified';
      } else if ($job->certified == 'yes') {
        $data['job_for'][] = 'certified';
      } else {
        $data['job_for'][] = $job->certified;
      }
    }

    $data['customer_town'] = $cuser->userMeta->city;
    $data['customer_type'] = $cuser->userMeta->customer_type;

    // Fire event or send notification to translators here

    return $response;
  }

  /**
   * Set gender and certified fields based on job_for array.
   *
   * @param array $data
   * @return array
   */
  private function setGenderAndCertified($data)
  {
    if (in_array('male', $data['job_for'])) {
      $data['gender'] = 'male';
    } else if (in_array('female', $data['job_for'])) {
      $data['gender'] = 'female';
    }

    if (in_array('normal', $data['job_for'])) {
      $data['certified'] = 'normal';
    } else if (in_array('certified', $data['job_for'])) {
      $data['certified'] = 'yes';
    } else if (in_array('certified_in_law', $data['job_for'])) {
      $data['certified'] = 'law';
    } else if (in_array('certified_in_helth', $data['job_for'])) {
      $data['certified'] = 'health';
    }

    if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
      $data['certified'] = 'both';
    } else if (in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])) {
      $data['certified'] = 'n_law';
    } else if (in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])) {
      $data['certified'] = 'n_health';
    }

    return $data;
  }

  /**
   * Set job type based on consumer type.
   *
   * @param string $consumer_type
   * @return string
   */
  private function setJobType($consumer_type)
  {
    return match ($consumer_type) {
      'rwsconsumer' => 'rws',
      'ngo' => 'unpaid',
      'paid' => 'paid',
      default => '',
    };
  }


  /**
   * Store job email and send notification.
   *
   * @param array $data
   * @return array
   */
  public function storeJobEmail($data)
  {
    $user_type = $data['user_type'];
    $job = Job::findOrFail($data['user_email_job_id']);
    $job->user_email = $data['user_email'] ?? '';
    $job->reference = $data['reference'] ?? '';

    if (isset($data['address'])) {
      $job->address = $data['address'] ?: $job->user->userMeta->address;
      $job->instructions = $data['instructions'] ?: $job->user->userMeta->instructions;
      $job->town = $data['town'] ?: $job->user->userMeta->city;
    }

    $job->save();

    $user = $job->user()->first();
    $email = !empty($job->user_email) ? $job->user_email : $user->email;
    $name = $user->name;
    $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
    $send_data = [
      'user' => $user,
      'job' => $job
    ];
    $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

    $response['type'] = $user_type;
    $response['job'] = $job;
    $response['status'] = 'success';
    $data = $this->jobToData($job);
    Event::fire(new JobWasCreated($job, $data, '*'));
    return $response;
  }

  /**
   * Convert job details to array data.
   *
   * @param Job $job
   * @return array
   */
  public function jobToData(Job $job)
  {
    $due_Date = explode(" ", $job->due);
    $due_date = $due_Date[0];
    $due_time = $due_Date[1];

    $data = [
      'job_id' => $job->id,
      'from_language_id' => $job->from_language_id,
      'immediate' => $job->immediate,
      'duration' => $job->duration,
      'status' => $job->status,
      'gender' => $job->gender,
      'certified' => $job->certified,
      'due' => $job->due,
      'job_type' => $job->job_type,
      'customer_phone_type' => $job->customer_phone_type,
      'customer_physical_type' => $job->customer_physical_type,
      'customer_town' => $job->town,
      'customer_type' => $job->user->userMeta->customer_type,
      'due_date' => $due_date,
      'due_time' => $due_time,
      'job_for' => []
    ];

    if ($job->gender != null) {
      $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
    }

    if ($job->certified != null) {
      if ($job->certified == 'both') {
        $data['job_for'][] = 'Godkänd tolk';
        $data['job_for'][] = 'Auktoriserad';
      } else if ($job->certified == 'yes') {
        $data['job_for'][] = 'Auktoriserad';
      } else if ($job->certified == 'n_health') {
        $data['job_for'][] = 'Sjukvårdstolk';
      } else if ($job->certified == 'law' || $job->certified == 'n_law') {
        $data['job_for'][] = 'Rättstolk';
      } else {
        $data['job_for'][] = $job->certified;
      }
    }

    return $data;
  }

  /**
   * End job session and send notifications.
   *
   * @param array $post_data
   */
  public function jobEnd($post_data = [])
  {
    $completeddate = date('Y-m-d H:i:s');
    $jobid = $post_data["job_id"];
    $job = Job::with('translatorJobRel')->find($jobid);

    $job->end_at = $completeddate;
    $job->status = 'completed';

    $start = Carbon::parse($job->due);
    $end = Carbon::parse($completeddate);
    $interval = $end->diffAsCarbonInterval($start);
    $job->session_time = $interval->forHumans(['parts' => 2]);

    $job->save();

    // Notify user about session end
    $this->sendSessionEndNotification($job, $post_data['userid'], 'emails.session-ended', 'faktura');

    // Notify translator about session end
    $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
    $this->sendSessionEndNotification($job, $tr->user_id, 'emails.session-ended', 'lön');

    $tr->completed_at = $completeddate;
    $tr->completed_by = $post_data['userid'];
    $tr->save();
  }

  /**
   * Send session end notification.
   *
   * @param Job $job
   * @param int $userId
   * @param string $view
   * @param string $forText
   */
  private function sendSessionEndNotification(Job $job, int $userId, string $view, string $forText)
  {
    $user = $userId == $job->user_id ? $job->user : $job->translatorJobRel->user;
    $email = $user->email;
    $name = $user->name;
    $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
    $session_explode = explode(':', $job->session_time);
    $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';

    $data = [
      'user' => $user,
      'job' => $job,
      'session_time' => $session_time,
      'for_text' => $forText
    ];

    $this->mailer->send($email, $name, $subject, $view, $data);

    Event::fire(new SessionEnded($job, $user->id));
  }


  /**
   * Function to get all Potential jobs of user with his ID
   * @param $user_id
   * @return array
   */
  public function getPotentialJobIdsWithUserId($user_id)
  {
    $user_meta = UserMeta::where('user_id', $user_id)->first();
    $translator_type = $user_meta->translator_type;
    $job_type = 'unpaid';

    if ($translator_type == 'professional') {
      $job_type = 'paid'; // Show all jobs for professionals.
    } elseif ($translator_type == 'rwstranslator') {
      $job_type = 'rws'; // For rwstranslator only show rws jobs.
    } elseif ($translator_type == 'volunteer') {
      $job_type = 'unpaid'; // For volunteers only show unpaid jobs.
    }

    $languages = UserLanguages::where('user_id', '=', $user_id)->get();
    $user_languages = $languages->pluck('lang_id')->all();
    $gender = $user_meta->gender;
    $translator_level = $user_meta->translator_level;

    $job_ids = Job::getJobs($user_id, $job_type, 'pending', $user_languages, $gender, $translator_level);

    foreach ($job_ids as $k => $v) {
      // Checking translator town
      $job = Job::find($v->id);
      $job_user_id = $job->user_id;
      $check_town = Job::checkTowns($job_user_id, $user_id);

      if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') &&
        $job->customer_physical_type == 'yes' &&
        !$check_town) {
        unset($job_ids[$k]);
      }
    }

    $jobs = TeHelper::convertJobIdsInObjs($job_ids);

    return $jobs;
  }


  /**
   * @param $job
   * @param array $data
   * @param $exclude_user_id
   */
  public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
  {
    $users = User::where('user_type', '2')->where('status', '1')->where('id', '!=', $exclude_user_id)->get();
    $translator_array = [];         // Suitable translators (no need to delay push)
    $delpay_translator_array = [];  // Suitable translators (need to delay push)

    foreach ($users as $oneUser) {
      if (!$this->isNeedToSendPush($oneUser->id)) {
        continue;
      }

      $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');

      if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') {
        continue;
      }

      $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id);

      foreach ($jobs as $oneJob) {
        if ($job->id != $oneJob->id) {
          continue;
        }

        $job_for_translator = Job::assignedToPaticularTranslator($oneUser->id, $oneJob->id);
        if ($job_for_translator == 'SpecificJob') {
          continue;
        }

        $job_checker = Job::checkParticularJob($oneUser->id, $oneJob);

        if ($job_checker != 'userCanNotAcceptJob') {
          continue;
        }

        if ($this->isNeedToDelayPush($oneUser->id)) {
          $delpay_translator_array[] = $oneUser;
        } else {
          $translator_array[] = $oneUser;
        }
      }
    }

    $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
    $data['notification_type'] = 'suitable_job';

    $msg_contents = $data['immediate'] == 'no'
      ? "Ny bokning för {$data['language']} tolk {$data['duration']}min {$data['due']}"
      : "Ny akutbokning för {$data['language']} tolk {$data['duration']}min";

    $msg_text = ["en" => $msg_contents];

    $logger = new Logger('push_logger');
    $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
    $logger->pushHandler(new FirePHPHandler());
    $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);

    $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false); // Send new booking push to suitable translators (not delay)
    $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // Send new booking push to suitable translators (need to delay)
  }

  /**
   * Sends SMS to translators and returns count of translators
   * @param $job
   * @return int
   */
  public function sendSMSNotificationToTranslator($job)
  {
    $translators = $this->getPotentialTranslators($job);
    $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

    // Prepare message templates
    $date = date('d.m.Y', strtotime($job->due));
    $time = date('H:i', strtotime($job->due));
    $duration = $this->convertToHoursMins($job->duration);
    $jobId = $job->id;
    $city = $job->city ? $job->city : $jobPosterMeta->city;

    $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
    $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

    // Analyze whether it's phone or physical; if both = default to phone
    $message = match (true) {
      $job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no' => $physicalJobMessageTemplate,
      $job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes' => $phoneJobMessageTemplate,
      $job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes' => $phoneJobMessageTemplate,
      default => ''
    };

    Log::info($message);

    // Send messages via SMS handler
    foreach ($translators as $translator) {
      // Send message to translator
      $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
      Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
    }

    return count($translators);
  }

  /**
   * Function to delay the push
   * @param $user_id
   * @return bool
   */
  public function isNeedToDelayPush($user_id)
  {
    if (!DateTimeHelper::isNightTime()) return false;
    $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
    if ($not_get_nighttime == 'yes') return true;
    return false;
  }

  /**
   * Function to check if need to send the push
   * @param $user_id
   * @return bool
   */
  public function isNeedToSendPush($user_id)
  {
    $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
    if ($not_get_notification == 'yes') return false;
    return true;
  }

  /**
   * Function to send Onesignal Push Notifications with User-Tags
   * @param $users
   * @param $job_id
   * @param $data
   * @param $msg_text
   * @param $is_need_delay
   */
  public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
  {
    $logger = new Logger('push_logger');
    $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
    $logger->pushHandler(new FirePHPHandler());
    $logger->info('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

    $environment = env('APP_ENV') === 'prod' ? 'prod' : 'dev';
    $onesignalAppID = config("app.{$environment}OnesignalAppID");
    $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config("app.{$environment}OnesignalApiKey"));

    $user_tags = $this->getUserTagsStringFromArray($users);
    $data['job_id'] = $job_id;

    [$ios_sound, $android_sound] = $data['notification_type'] === 'suitable_job'
      ? ($data['immediate'] === 'no' ? ['normal_booking.mp3', 'normal_booking'] : ['emergency_booking.mp3', 'emergency_booking'])
      : ['default', 'default'];

    $fields = [
      'app_id' => $onesignalAppID,
      'tags' => json_decode($user_tags),
      'data' => $data,
      'title' => ['en' => 'DigitalTolk'],
      'contents' => $msg_text,
      'ios_badgeType' => 'Increase',
      'ios_badgeCount' => 1,
      'android_sound' => $android_sound,
      'ios_sound' => $ios_sound
    ];

    if ($is_need_delay) {
      $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
    }

    $fields = json_encode($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

    $response = curl_exec($ch);
    $logger->info('Push send for job ' . $job_id . ' curl answer', [$response]);
    curl_close($ch);
  }


  /**
   * @param Job $job
   * @return mixed
   */
  public function getPotentialTranslators(Job $job)
  {
    $translator_type = match ($job->job_type) {
      'paid' => 'professional',
      'rws' => 'rwstranslator',
      'unpaid' => 'volunteer',
      default => 'professional',
    };

    $translator_level = match ($job->certified) {
      'yes', 'both' => ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care'],
      'law', 'n_law' => ['Certified with specialisation in law'],
      'health', 'n_health' => ['Certified with specialisation in health care'],
      'normal', 'both' => ['Layman', 'Read Translation courses'],
      null => ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'],
      default => [],
    };

    $blacklist = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();
    return User::getPotentialUsers($translator_type, $job->from_language_id, $job->gender, $translator_level, $blacklist);
  }


  /**
   * Update job details.
   *
   * @param int $id
   * @param array $data
   * @param object $cuser
   * @return array
   */
  public function updateJob($id, $data, $cuser)
  {
    $job = Job::find($id);
    $currentTranslator = $this->getCurrentTranslator($job);

    $logData = [];
    $langChanged = false;

    $translatorChange = $this->changeTranslator($currentTranslator, $data, $job);
    if ($translatorChange['translatorChanged']) {
      $logData[] = $translatorChange['log_data'];
    }

    $dueChange = $this->changeDue($job->due, $data['due']);
    if ($dueChange['dateChanged']) {
      $oldDue = $job->due;
      $job->due = $data['due'];
      $logData[] = $dueChange['log_data'];
    }

    if ($job->from_language_id != $data['from_language_id']) {
      $logData[] = $this->getLanguageChangeLog($job->from_language_id, $data['from_language_id']);
      $oldLang = $job->from_language_id;
      $job->from_language_id = $data['from_language_id'];
      $langChanged = true;
    }

    $statusChange = $this->changeStatus($job, $data, $translatorChange['translatorChanged']);
    if ($statusChange['statusChanged']) {
      $logData[] = $statusChange['log_data'];
    }

    $job->admin_comments = $data['admin_comments'];
    $job->reference = $data['reference'];

    $this->logger->addInfo('USER #' . $cuser->id . ' (' . $cuser->name . ') updated booking #' . $id, $logData);

    $job->save();
    $this->handlePostSaveActions($job, $oldDue ?? null, $translatorChange, $currentTranslator, $langChanged, $oldLang ?? null);

    return ['Updated'];
  }

  private function getCurrentTranslator($job)
  {
    return $job->translatorJobRel->whereNull('cancel_at')->first()
      ?? $job->translatorJobRel->whereNotNull('completed_at')->first();
  }

  private function getLanguageChangeLog($oldLanguageId, $newLanguageId)
  {
    return [
      'old_lang' => TeHelper::fetchLanguageFromJobId($oldLanguageId),
      'new_lang' => TeHelper::fetchLanguageFromJobId($newLanguageId)
    ];
  }

  private function handlePostSaveActions($job, $oldDue, $translatorChange, $currentTranslator, $langChanged, $oldLang)
  {
    if ($job->due > Carbon::now()) {
      if ($translatorChange['dateChanged']) {
        $this->sendChangedDateNotification($job, $oldDue);
      }
      if ($translatorChange['translatorChanged']) {
        $this->sendChangedTranslatorNotification($job, $currentTranslator, $translatorChange['new_translator']);
      }
      if ($langChanged) {
        $this->sendChangedLangNotification($job, $oldLang);
      }
    }
  }

  /**
   * Change job status.
   *
   * @param Job $job
   * @param array $data
   * @param bool $changedTranslator
   * @return array
   */
  private function changeStatus($job, $data, $changedTranslator)
  {
    $oldStatus = $job->status;
    if ($oldStatus != $data['status']) {
      $statusChanged = $this->handleStatusChange($job, $data, $changedTranslator, $oldStatus);
      if ($statusChanged) {
        return [
          'statusChanged' => true,
          'log_data' => [
            'old_status' => $oldStatus,
            'new_status' => $data['status']
          ]
        ];
      }
    }
    return ['statusChanged' => false];
  }

  private function handleStatusChange($job, $data, $changedTranslator, $oldStatus)
  {
    $statusChangeMethods = [
      'timedout' => 'changeTimedoutStatus',
      'completed' => 'changeCompletedStatus',
      'started' => 'changeStartedStatus',
      'pending' => 'changePendingStatus',
      'withdrawafter24' => 'changeWithdrawafter24Status',
      'assigned' => 'changeAssignedStatus'
    ];

    if (isset($statusChangeMethods[$oldStatus])) {
      return $this->{$statusChangeMethods[$oldStatus]}($job, $data, $changedTranslator);
    }

    return false;
  }

  private function changeTimedoutStatus($job, $data, $changedTranslator)
  {
    $job->status = $data['status'];
    $user = $job->user()->first();
    $email = $job->user_email ?: $user->email;
    $name = $user->name;
    $dataEmail = ['user' => $user, 'job' => $job];

    if ($data['status'] == 'pending') {
      $this->handlePendingStatus($job, $dataEmail, $email, $name);
      return true;
    } elseif ($changedTranslator) {
      $job->save();
      $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
      $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
      return true;
    }

    return false;
  }

  private function handlePendingStatus($job, $dataEmail, $email, $name)
  {
    $job->created_at = now();
    $job->emailsent = 0;
    $job->emailsenttovirpal = 0;
    $job->save();

    $jobData = $this->jobToData($job);
    $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
    $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);
    $this->sendNotificationTranslator($job, $jobData, '*');
  }

  private function changeCompletedStatus($job, $data)
  {
    $job->status = $data['status'];
    if ($data['status'] == 'timedout' && empty($data['admin_comments'])) {
      return false;
    }

    $job->admin_comments = $data['admin_comments'] ?? null;
    $job->save();
    return true;
  }

  private function changeStartedStatus($job, $data)
  {
    $job->status = $data['status'];
    if (empty($data['admin_comments'])) {
      return false;
    }

    $job->admin_comments = $data['admin_comments'];
    if ($data['status'] == 'completed') {
      return $this->handleCompletedStatus($job, $data);
    }

    $job->save();
    return true;
  }

  private function handleCompletedStatus($job, $data)
  {
    $user = $job->user()->first();
    if (empty($data['sesion_time'])) {
      return false;
    }

    $interval = $data['sesion_time'];
    $diff = explode(':', $interval);
    $job->end_at = now();
    $job->session_time = $interval;
    $sessionTime = $diff[0] . ' tim ' . $diff[1] . ' min';
    $email = $job->user_email ?: $user->email;
    $name = $user->name;
    $dataEmail = ['user' => $user, 'job' => $job, 'session_time' => $sessionTime, 'for_text' => 'faktura'];

    $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
    $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

    $translator = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();
    if ($translator) {
      $email = $translator->user->email;
      $name = $translator->user->name;
      $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
      $dataEmail['for_text'] = 'lön';
      $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
    }

    return true;
  }

  /**
   * @param $job
   * @param $data
   * @param $changedTranslator
   * @return bool
   */
  /**
   * Change job status to pending.
   *
   * @param Job $job
   * @param array $data
   * @param bool $changedTranslator
   * @return bool
   */
  private function changePendingStatus($job, $data, $changedTranslator)
  {
    $allowedStatuses = ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'];
    $job->status = $data['status'];

    if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
      return false;
    }

    $job->admin_comments = $data['admin_comments'];

    $user = $job->user()->first();
    $email = $job->user_email ?: $user->email;
    $name = $user->name;
    $dataEmail = [
      'user' => $user,
      'job' => $job
    ];

    if ($data['status'] == 'assigned' && $changedTranslator) {
      $this->handleAssignedStatus($job, $dataEmail, $email, $name);
      return true;
    } else {
      $this->handleCancelledStatus($job, $dataEmail, $email, $name);
      return true;
    }

    return false;
  }

  private function handleAssignedStatus($job, $dataEmail, $email, $name)
  {
    $job->save();
    $jobData = $this->jobToData($job);

    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
    $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

    $translator = Job::getJobsAssignedTranslatorDetail($job);
    $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

    $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
    $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
  }

  private function handleCancelledStatus($job, $dataEmail, $email, $name)
  {
    $subject = 'Avbokning av bokningsnr: #' . $job->id;
    $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
    $job->save();
  }

  /**
   * TODO remove method and add service for notification
   * TEMP method
   * send session start remind notification
   * Send session start reminder notification.
   *
   * @param object $user
   * @param Job $job
   * @param string $language
   * @param string $due
   * @param int $duration
   */
  public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
  {
    $this->setupLogger();

    $data = [
      'notification_type' => 'session_start_remind'
    ];

    $dueParts = explode(' ', $due);
    $msgText = [
      'en' => 'Detta är en påminnelse om att du har en ' . $language . ' tolkning (' . $this->getSessionTypeText($job) . ') kl ' . $dueParts[1] . ' på ' . $dueParts[0] . ' som varar i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
    ];

    if ($this->bookingRepository->isNeedToSendPush($user->id)) {
      $this->sendPushNotification($user, $job, $data, $msgText);
      $this->logger->addInfo('sendSessionStartRemindNotification', ['job' => $job->id]);
    }
  }

  /**
   * Setup logger with necessary handlers.
   */
  private function setupLogger()
  {
    $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
    $this->logger->pushHandler(new FirePHPHandler());
  }

  /**
   * Get session type text based on job details.
   *
   * @param Job $job
   * @return string
   */
  private function getSessionTypeText($job)
  {
    if ($job->customer_physical_type == 'yes') {
      return 'på plats i ' . $job->town;
    } else {
      return 'telefon';
    }
  }

  /**
   * Send push notification to specific users.
   *
   * @param array $users
   * @param int $jobId
   * @param array $data
   * @param array $msgText
   */
  private function sendPushNotification($users, $jobId, $data, $msgText)
  {
    $usersArray = [$users];
    $this->bookingRepository->sendPushNotificationToSpecificUsers($usersArray, $jobId, $data, $msgText, $this->bookingRepository->isNeedToDelayPush($users->id));
  }

  /**
   * @param $job
   * @param $data
   * @return bool
   */
  private function changeWithdrawafter24Status($job, $data)
  {
    if (!in_array($data['status'], ['timedout'])) {
      return false;
    }

    $job->status = $data['status'];
    if ($data['admin_comments'] == '') return false;
    $job->admin_comments = $data['admin_comments'];
    $job->save();
    return true;
  }

  /**
   * Change the assigned status of a job and handle related actions.
   *
   * @param $job
   * @param $data
   * @return bool
   */
  private function changeAssignedStatus($job, $data)
  {
    $validStatuses = ['withdrawbefore24', 'withdrawafter24', 'timedout'];

    if (!in_array($data['status'], $validStatuses)) {
      return  false;
    }

    $job->status = $data['status'];

    if ($data['status'] === 'timedout' && $data['admin_comments'] === '') {
      return false;
    }

    $job->admin_comments = $data['admin_comments'];

    if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
      $user = $job->user()->first();
      $email = $job->user_email ?? $user->email;
      $name = $user->name;

      $dataEmail = [
        'user' => $user,
        'job' => $job
      ];

      $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
      $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

      $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();

      if ($translator) {
        $translatorEmail = $translator->user->email;
        $translatorName = $translator->user->name;

        $dataEmail = [
          'user' => $translator,
          'job' => $job
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $this->mailer->send($translatorEmail, $translatorName, $subject, 'emails.job-cancel-translator', $dataEmail);
      }
    }

    $job->save();
    return true;
  }


  /**
   * @param $current_translator
   * @param $data
   * @param $job
   * @return array
   */
  private function changeTranslator($current_translator, $data, $job)
  {
    $translatorChanged = false;
    $log_data = [];

    if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
      if (!is_null($current_translator) &&
        ($current_translator->user_id != $data['translator'] || !empty($data['translator_email'])) && !empty($data['translator'])) {
        if ($data['translator_email'] != '') {
          $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
        }
        $new_translator = $current_translator->replicate();
        $new_translator->user_id = $data['translator'];
        $new_translator->save();
        $current_translator->cancel_at = Carbon::now();
        $current_translator->save();
        $log_data[] = [
          'old_translator' => $current_translator->user->email,
          'new_translator' => $new_translator->user->email
        ];
        $translatorChanged = true;
      } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
        if ($data['translator_email'] != '') {
          $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
        }
        $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
        $log_data[] = [
          'old_translator' => null,
          'new_translator' => $new_translator->user->email
        ];
        $translatorChanged = true;
      }

      if ($translatorChanged) {
        return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
      }
    }

    return ['translatorChanged' => $translatorChanged];
  }

  /**
   * @param $old_due
   * @param $new_due
   * @return array
   */
  private function changeDue($old_due, $new_due)
  {
    if ($old_due == $new_due) {
       return ['dateChanged' => false];
    }

    return ['dateChanged' => true, 'log_data' => [
      'old_due' => $old_due,
      'new_due' => $new_due
    ]];


  }

  /**
   * @param $job
   * @param $current_translator
   * @param $new_translator
   */
  public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
  {
    $user = $job->user()->first();
    $email = $job->user_email ??  $user->email;
    $name = $user->name;
    $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
    $data = [
      'user' => $user,
      'job' => $job
    ];
    $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
    $this->mailToTranslator($current_translator,$subject,$data,'emails.job-changed-translator-old-translator');
    $this->mailToTranslator($new_translator,$subject,$data);


  }

  private function mailToTranslator($new_translator,$subject,$data,$template='emails.job-changed-translator-new-translator'){
    $user = $new_translator->user;
    $name = $user->name;
    $email = $user->email;
    $data['user'] = $user;
    $this->mailer->send($email, $name, $subject, $template, $data);
  }

  /**
   * @param $job
   * @param $old_time
   */
  public function sendChangedDateNotification($job, $old_time)
  {
    // Fetch user details
    $user = $job->user()->first();
    $email = $job->user_email ?? $user->email;
    $name = $user->name;

    // Prepare email data
    $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
    $userData = [
      'user' => $user,
      'job' => $job,
      'old_time' => $old_time
    ];

    // Send notification to the user
    $this->sendEmail($email, $name, $subject,'emails.job-changed-date', $userData);

    // Send notification to the assigned translator
    $translator = Job::getJobsAssignedTranslatorDetail($job);
    if ($translator) {
      $translatorData = [
        'job' => $job,
        'old_time' => $old_time
      ];
      $this->sendEmail($translator->email, $translator->name, $subject,'emails.job-changed-date', $translatorData);
    }
  }



  protected function sendEmail($email, $name, $subject, $template, $data)
  {
    // Send email using mailer service
    $this->mailer->send($email, $name, $subject, $template, $data);
  }
  /**
   * @param $job
   * @param $old_lang
   */
  public function sendChangedLangNotification($job, $old_lang)
  {
    // Fetch user details
    $user = $job->user()->first();
    $email = $job->user_email ?? $user->email;
    $name = $user->name;

    // Prepare email data
    $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
    $userData = [
      'user' => $user,
      'job' => $job,
      'old_lang' => $old_lang
    ];

    // Send notification to the user
    $this->sendEmail($email, $name, $subject, 'emails.job-changed-lang', $userData);

    // Send notification to the assigned translator
    $translator = Job::getJobsAssignedTranslatorDetail($job);
    if ($translator) {
      $this->sendEmail($translator->email, $translator->name, $subject, 'emails.job-changed-date', $userData);
    }
  }

  /**
   * Function to send Job Expired Push Notification
   * @param $job
   * @param $user
   */
  public function sendExpiredNotification($job, $user)
  {
    if (!$this->isNeedToSendPush($user->id)) {
      return;
    }

    $data = array();
    $data['notification_type'] = 'job_expired';
    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
    $msg_text = [
      "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
    ];


    $users_array = array($user);
    $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));

  }

  /**
   * Function to send the notification for sending the admin job cancel
   * @param $job_id
   */
  public function sendNotificationByAdminCancelJob($job_id)
  {
    $job = Job::findOrFail($job_id);
    $user_meta = $job->user->userMeta()->first();
    $data = $job->only(['from_language_id','immediate','duration','status','gender',
      'certified','due','job_type','customer_phone_type','customer_physical_type',
      'customer_town','customer_type']);            // save job's information to data for sending Push
    $data['job_id'] = $job->id;
    $due_Date = explode(" ", $job->due);
    $due_date = $due_Date[0];
    $due_time = $due_Date[1];
    $data['due_date'] = $due_date;
    $data['due_time'] = $due_time;
    $data['job_for'] = array();
    if ($job->gender != null) {
      if ($job->gender == 'male') {
        $data['job_for'][] = 'Man';
      } else if ($job->gender == 'female') {
        $data['job_for'][] = 'Kvinna';
      }
    }
    if ($job->certified != null) {
      if ($job->certified == 'both') {
        $data['job_for'][] = 'normal';
        $data['job_for'][] = 'certified';
      } else if ($job->certified == 'yes') {
        $data['job_for'][] = 'certified';
      } else {
        $data['job_for'][] = $job->certified;
      }
    }
    $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
  }
  /**
   * send session start remind notificatio
   * @param $user
   * @param $job
   * @param $language
   * @param $due
   * @param $duration
   */
  private function sendNotificationChangePending($user, $job, $language, $due, $duration)
  {
    $data = array();
    $data['notification_type'] = 'session_start_remind';
    if ($job->customer_physical_type == 'yes')
      $msg_text = array(
        "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
      );
    else
      $msg_text = array(
        "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
      );

    if ($this->bookingRepository->isNeedToSendPush($user->id)) {
      $users_array = array($user);
      $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
    }
  }

  /**
   * making user_tags string from users array for creating onesignal notifications
   * @param $users
   * @return string
   */
  private function getUserTagsStringFromArray($users)
  {
    $user_tags = "[";
    $first = true;
    foreach ($users as $oneUser) {
      if ($first) {
        $first = false;
      } else {
        $user_tags .= ',{"operator": "OR"},';
      }
      $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
    }
    $user_tags .= ']';
    return $user_tags;
  }

  /**
   * @param $data
   * @param $user
   */
  public function acceptJob($data, $user)
  {

    $adminemail = config('app.admin_email');
    $adminSenderEmail = config('app.admin_sender_email');

    $cuser = $user;
    $job_id = $data['job_id'];
    $job = Job::findOrFail($job_id);
    if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
      if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
        $job->status = 'assigned';
        $job->save();
        $user = $job->user()->get()->first();
        $mailer = new AppMailer();

        if (!empty($job->user_email)) {
          $email = $job->user_email;
          $name = $user->name;
          $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        } else {
          $email = $user->email;
          $name = $user->name;
          $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        }
        $data = [
          'user' => $user,
          'job' => $job
        ];
        $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

      }
      /*@todo
          add flash message here.
      */
      $jobs = $this->getPotentialJobs($cuser);
      $response = array();
      $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
      $response['status'] = 'success';
    } else {
      $response['status'] = 'fail';
      $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
    }

    return $response;

  }

  /*Function to accept the job with the job id*/
  public function acceptJobWithId($job_id, $cuser)
  {
    $job = Job::findOrFail($job_id);
    $response = [];

    if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
      return $this->buildFailResponse('Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning');
    }

    if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
      $this->assignJobToTranslator($job, $cuser);
      return $this->buildSuccessResponse($job);
    } else {
      $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
      return $this->buildFailResponse('Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning');
    }
  }

  private function assignJobToTranslator($job, $cuser)
  {
    $job->status = 'assigned';
    $job->save();

    $user = $job->user()->first();
    $this->sendAcceptanceEmail($job, $user);
    $this->sendAcceptanceNotification($job, $user);
  }

  private function sendAcceptanceEmail($job, $user)
  {
    $mailer = new AppMailer();
    $email = $job->user_email ?? $user->email;
    $name = $user->name;
    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
    $data = ['user' => $user, 'job' => $job];

    $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
  }

  private function sendAcceptanceNotification($job, $user)
  {
    $data = ['notification_type' => 'job_accepted'];
    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
    $msg_text = [
      "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
    ];

    if ($this->isNeedToSendPush($user->id)) {
      $users_array = [$user];
      $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
    }
  }

  private function buildSuccessResponse($job)
  {
    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
    return [
      'status' => 'success',
      'list' => ['job' => $job],
      'message' => 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due
    ];
  }

  private function buildFailResponse($message)
  {
    return [
      'status' => 'fail',
      'message' => $message
    ];
  }
  public function cancelJobAjax($data, $user)
  {
    $response = [];
    $cuser = $user;
    $job_id = $data['job_id'];
    $job = Job::findOrFail($job_id);
    $translator = Job::getJobsAssignedTranslatorDetail($job);

    if ($cuser->is('customer')) {
      $this->handleCustomerCancellation($job, $translator, $response);
    } else {
      $this->handleTranslatorCancellation($job, $translator, $response);
    }

    return $response;
  }

  private function handleCustomerCancellation($job, $translator, &$response)
  {
    $job->withdraw_at = Carbon::now();
    if ($job->withdraw_at->diffInHours($job->due) >= 24) {
      $job->status = 'withdrawbefore24';
    } else {
      $job->status = 'withdrawafter24';
    }

    $job->save();
    Event::fire(new JobWasCanceled($job));
    $response['status'] = 'success';
    $response['jobstatus'] = 'success';

    if ($translator) {
      $this->notifyTranslatorOfCancellation($translator, $job);
    }
  }

  private function handleTranslatorCancellation($job, $translator, &$response)
  {
    if ($job->due->diffInHours(Carbon::now()) > 24) {
      $this->notifyCustomerOfCancellation($job);
      $this->reopenJob($job);
      Job::deleteTranslatorJobRel($translator->id, $job->id);
      $data = $this->jobToData($job);
      $this->sendNotificationTranslator($job, $data, $translator->id);
      $response['status'] = 'success';
    } else {
      $response['status'] = 'fail';
      $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
    }
  }

  private function notifyTranslatorOfCancellation($translator, $job)
  {
    $data = ['notification_type' => 'job_cancelled'];
    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
    $msg_text = [
      "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
    ];

    if ($this->isNeedToSendPush($translator->id)) {
      $users_array = [$translator];
      $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));
    }
  }

  private function notifyCustomerOfCancellation($job)
  {
    $customer = $job->user()->first();
    if ($customer) {
      $data = ['notification_type' => 'job_cancelled'];
      $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
      $msg_text = [
        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
      ];

      if ($this->isNeedToSendPush($customer->id)) {
        $users_array = [$customer];
        $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));
      }
    }
  }

  private function reopenJob($job)
  {
    $job->status = 'pending';
    $job->created_at = now();
    $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
    $job->save();
  }
  /*Function to get the potential jobs for paid,rws,unpaid translators*/
  public function getPotentialJobs($cuser)
  {
    $job_type = $this->determineJobType($cuser->userMeta->translator_type);
    $userLanguages = $this->getUserLanguages($cuser->id);
    $gender = $cuser->userMeta->gender;
    $translator_level = $cuser->userMeta->translator_level;

    $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userLanguages, $gender, $translator_level);

    foreach ($job_ids as $key => $job) {
      if ($this->shouldRemoveJob($cuser, $job)) {
        unset($job_ids[$key]);
      }
    }

    return $job_ids;
  }

  private function determineJobType($translator_type)
  {
    return match ($translator_type) {
      'professional' => 'paid',
      'rwstranslator' => 'rws',
      'volunteer' => 'unpaid',
      default => 'unpaid',
    };
  }

  private function getUserLanguages($userId)
  {
    $languages = UserLanguages::where('user_id', $userId)->pluck('lang_id')->all();
    return collect($languages);
  }

  private function shouldRemoveJob($cuser, $job)
  {
    $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
    $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
    $checktown = Job::checkTowns($job->user_id, $cuser->id);

    if ($job->specific_job == 'SpecificJob' && $job->check_particular_job == 'userCanNotAcceptJob') {
      return true;
    }

    if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checktown) {
      return true;
    }

    return false;
  }

  public function endJob($post_data)
  {
    $completeddate = now()->format('Y-m-d H:i:s');
    $jobid = $post_data["job_id"];
    $job_detail = Job::with('translatorJobRel')->find($jobid);

    if ($job_detail->status != 'started') {
      return ['status' => 'success'];
    }

    $interval = $this->calculateSessionTime($job_detail->due, $completeddate);
    $this->updateJobDetails($job_detail, $completeddate, $interval);
    $this->sendSessionEndedEmails($job_detail, $interval, $post_data['user_id']);

    return ['status' => 'success'];
  }

  private function calculateSessionTime($dueDate, $completedDate)
  {
    $start = date_create($dueDate);
    $end = date_create($completedDate);
    $diff = date_diff($end, $start);

    return $diff->format('%h:%i:%s');
  }

  private function updateJobDetails($job, $completeddate, $interval)
  {
    $job->end_at = $completeddate;
    $job->status = 'completed';
    $job->session_time = $interval;
    $job->save();
  }

  private function sendSessionEndedEmails($job, $sessionTime, $userId)
  {
    $completeddate = date('Y-m-d H:i:s');
    $user = $job->user;
    $email = !empty($job->user_email) ? $job->user_email : $user->email;
    $name = $user->name;
    $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

    $data = [
      'user' => $user,
      'job' => $job,
      'session_time' => $sessionTime,
      'for_text' => 'faktura'
    ];

    $this->sendEmailByAppMailer($email, $name, $subject, 'emails.session-ended', $data);

    $translator = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
    $translatorUser = $translator->user;
    $email = $translatorUser->email;
    $name = $translatorUser->name;
    $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

    $data = [
      'user' => $translatorUser,
      'job' => $job,
      'session_time' => $sessionTime,
      'for_text' => 'lön'
    ];

    $this->sendEmailByAppMailer($email, $name, $subject, 'emails.session-ended', $data);

    $translator->update([
      'completed_at' => $completeddate,
      'completed_by' => $userId,
    ]);
  }

  private function sendEmailByAppMailer($email, $name, $subject, $template, $data)
  {
    $mailer = new AppMailer();
    $mailer->send($email, $name, $subject, $template, $data);
  }

  public function customerNotCall($post_data)
  {
    $completeddate = date('Y-m-d H:i:s');
    $jobid = $post_data["job_id"];
    $job_detail = Job::with('translatorJobRel')->find($jobid);
    $duedate = $job_detail->due;
    $start = date_create($duedate);
    $end = date_create($completeddate);
    $diff = date_diff($end, $start);
    $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
    $job = $job_detail;
    $job->end_at = date('Y-m-d H:i:s');
    $job->status = 'not_carried_out_customer';

    $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
    $tr->completed_at = $completeddate;
    $tr->completed_by = $tr->user_id;
    $job->save();
    $tr->save();
    $response['status'] = 'success';
    return $response;
  }

  public function getAll(Request $request, $limit = null)
  {
    $requestdata = $request->all();
    $cuser = $request->__authenticatedUser;

    $allJobs = Job::query();

    if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
      $this->applySuperadminFilters($allJobs, $requestdata);

    } else {
      $this->applyRegularUserFilters($allJobs, $requestdata, $cuser->consumer_type);
    }

    $allJobs->orderBy('created_at', 'desc');
    $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

    return $limit == 'all' ? $allJobs->get() : $allJobs->paginate(15);
  }

  private function applySuperadminFilters($query, $data)
  {
    $this->applyCommonFilters($query, $data);

    $query->when(!empty($data['customer_email']), function ($q) use ($data) {
      $users = DB::table('users')->whereIn('email', $data['customer_email'])->get();
      if ($users) {
        $q->whereIn('user_id', collect($users)->pluck('id')->all());
      }
    });

    $query->when(!empty($data['translator_email']), function ($q) use ($data) {
      $users = DB::table('users')->whereIn('email', $data['translator_email'])->get();
      if ($users) {
        $jobIds = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->pluck('job_id');
        $q->whereIn('id', $jobIds);
      }
    });

    $query->when(!empty($data['filter_timetype']), function ($q) use ($data) {
      $this->applyTimeFilters($q, $data, $data['filter_timetype']);
    });

    $query->when(!empty($data['physical']), function ($q) use ($data) {
      $q->where('customer_physical_type', $data['physical']);
      $q->where('ignore_physical', 0);
    });

    $query->when(!empty($data['phone']), function ($q) use ($data) {
      $q->where('customer_phone_type', $data['phone']);
      if (!empty($data['physical'])) {
        $q->where('ignore_physical_phone', 0);
      }
    });

    $query->when(!empty($data['flagged']), function ($q) use ($data) {
      $q->where('flagged', $data['flagged']);
      $q->where('ignore_flagged', 0);
    });

    $query->when(!empty($data['distance']) && $data['distance'] == 'empty', function ($q) {
      $q->whereDoesntHave('distance');
    });

    $query->when(!empty($data['salary']) && $data['salary'] == 'yes', function ($q) {
      $q->whereDoesntHave('user.salaries');
    });

    $query->when(!empty($data['consumer_type']), function ($q) use ($data) {
      $q->whereHas('user.userMeta', function ($q) use ($data) {
        $q->where('consumer_type', $data['consumer_type']);
      });
    });

    $query->when(!empty($data['booking_type']), function ($q) use ($data) {
      if ($data['booking_type'] == 'physical') {
        $q->where('customer_physical_type', 'yes');
      } elseif ($data['booking_type'] == 'phone') {
        $q->where('customer_phone_type', 'yes');
      }
    });

    if (!empty($data['count']) && $data['count'] == 'true') {
      return ['count' => $query->count()];
    }
  }

  private function applyRegularUserFilters($query, $data, $consumer_type)
  {
    $query->when(!empty($data['id']), function ($q) use ($data) {
      $q->where('id', $data['id']);
    });

    $query->when($consumer_type == 'RWS', function ($q) {
      $q->where('job_type', 'rws');
    }, function ($q) {
      $q->where('job_type', 'unpaid');
    });

    $this->applyCommonFilters($query, $data);

    $query->when(!empty($data['customer_email']), function ($q) use ($data) {
      $user = DB::table('users')->where('email', $data['customer_email'])->first();
      if ($user) {
        $q->where('user_id', $user->id);
      }
    });

    $query->when(!empty($data['filter_timetype']), function ($q) use ($data) {
      $this->applyTimeFilters($q, $data, $data['filter_timetype']);
    });
  }

  private function applyCommonFilters($query, $data)
  {
    $query->when(!empty($data['feedback']) && $data['feedback'] != 'false', function ($q) use ($data) {
      $q->where('ignore_feedback', '0')
        ->whereHas('feedback', function ($q) {
          $q->where('rating', '<=', '3');
        });

      if (!empty($data['count']) && $data['count'] != 'false') {
        return ['count' => $q->count()];
      }
    });

    $query->when(!empty($data['lang']), function ($q) use ($data) {
      $q->whereIn('from_language_id', $data['lang']);
    });

    $query->when(!empty($data['status']), function ($q) use ($data) {
      $q->whereIn('status', $data['status']);
    });

    $query->when(!empty($data['job_type']), function ($q) use ($data) {
      $q->whereIn('job_type', $data['job_type']);
    });

    $query->when(!empty($data['expired_at']), function ($q) use ($data) {
      $q->where('expired_at', '>=', $data['expired_at']);
    });

    $query->when(!empty($data['will_expire_at']), function ($q) use ($data) {
      $q->where('will_expire_at', '>=', $data['will_expire_at']);
    });
  }

  private function applyTimeFilters($query, $data, $type)
  {
    if ($type == "created") {
      $query->when(!empty($data['from']), function ($q) use ($data) {
        $q->where('created_at', '>=', $data["from"]);
      });

      $query->when(!empty($data['to']), function ($q) use ($data) {
        $q->where('created_at', '<=', $data["to"] . " 23:59:00");
      });

      $query->orderBy('created_at', 'desc');
    } elseif ($type == "due") {
      $query->when(!empty($data['from']), function ($q) use ($data) {
        $q->where('due', '>=', $data["from"]);
      });

      $query->when(!empty($data['to']), function ($q) use ($data) {
        $q->where('due', '<=', $data["to"] . " 23:59:00");
      });

      $query->orderBy('due', 'desc');
    }
  }
  public function alerts()
  {
    $jobs = Job::all();
    $sesJobs = [];
    $jobId = [];
    $diff = [];

    foreach ($jobs as $job) {
      $sessionTime = explode(':', $job->session_time);
      if (count($sessionTime) >= 3) {
        $sessionMinutes = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
        $diff[] = $sessionMinutes;

        if ($sessionMinutes >= $job->duration && $sessionMinutes >= $job->duration * 2) {
          $sesJobs[] = $job;
        }
      }
    }

    foreach ($sesJobs as $job) {
      $jobId[] = $job->id;
    }

    $languages = Language::where('active', '1')->orderBy('language')->get();
    $requestdata = Request::all();
    $all_customers = DB::table('users')->where('user_type', '1')->pluck('email');
    $all_translators = DB::table('users')->where('user_type', '2')->pluck('email');

    $cuser = Auth::user();
    $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

    if ($cuser && $cuser->is('superadmin')) {
      $allJobs = $this->buildJobsQuery($jobId, $requestdata);
    }

    return [
      'allJobs' => $allJobs,
      'languages' => $languages,
      'all_customers' => $all_customers,
      'all_translators' => $all_translators,
      'requestdata' => $requestdata
    ];
  }

  private function buildJobsQuery($jobId, $requestdata)
  {
    $allJobs = DB::table('jobs')
      ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
      ->whereIn('jobs.id', $jobId)
      ->where('jobs.ignore', 0);

    $allJobs->when(!empty($requestdata['lang']), function ($q) use ($requestdata) {
      $q->whereIn('jobs.from_language_id', $requestdata['lang']);
    });

    $allJobs->when(!empty($requestdata['status']), function ($q) use ($requestdata) {
      $q->whereIn('jobs.status', $requestdata['status']);
    });

    $allJobs->when(!empty($requestdata['customer_email']), function ($q) use ($requestdata) {
      $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
      if ($user) {
        $q->where('jobs.user_id', $user->id);
      }
    });

    $allJobs->when(!empty($requestdata['translator_email']), function ($q) use ($requestdata) {
      $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
      if ($user) {
        $jobIds = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
        $q->whereIn('jobs.id', $jobIds);
      }
    });

    $allJobs->when(!empty($requestdata['filter_timetype']), function ($q) use ($requestdata) {
      $this->applyTimeFilters($q, $requestdata, $requestdata['filter_timetype']);
    });

    $allJobs->when(!empty($requestdata['job_type']), function ($q) use ($requestdata) {
      $q->whereIn('jobs.job_type', $requestdata['job_type']);
    });

    $allJobs->select('jobs.*', 'languages.language')
      ->orderBy('jobs.created_at', 'desc');

    return $allJobs->paginate(15);
  }

  public function userLoginFailed()
  {
    $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

    return ['throttles' => $throttles];
  }

  public function bookingExpireNoAccepted()
  {
    $languages = Language::where('active', '1')->orderBy('language')->get();
    $requestdata = Request::all();
    $all_customers = DB::table('users')->where('user_type', '1')->pluck('email');
    $all_translators = DB::table('users')->where('user_type', '2')->pluck('email');

    $cuser = Auth::user();
    $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

    $allJobs = collect();

    if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
      $allJobs = $this->getFilteredJobs($requestdata);
      $allJobs = $allJobs->paginate(15);
    }

    return [
      'allJobs' => $allJobs,
      'languages' => $languages,
      'all_customers' => $all_customers,
      'all_translators' => $all_translators,
      'requestdata' => $requestdata,
    ];
  }

  private function getFilteredJobs($requestdata)
  {
    $now = Carbon::now();

    $query = DB::table('jobs')
      ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
      ->where('jobs.ignore_expired', 0)
      ->where('jobs.status', 'pending')
      ->where('jobs.due', '>=', $now);

    $query->when(!empty($requestdata['lang']), function ($q) use ($requestdata) {
      $q->whereIn('jobs.from_language_id', $requestdata['lang']);
    });

    $query->when(!empty($requestdata['status']), function ($q) use ($requestdata) {
      $q->whereIn('jobs.status', $requestdata['status']);
    });

    $query->when(!empty($requestdata['customer_email']), function ($q) use ($requestdata) {
      $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
      if ($user) {
        $q->where('jobs.user_id', $user->id);
      }
    });

    $query->when(!empty($requestdata['translator_email']), function ($q) use ($requestdata) {
      $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
      if ($user) {
        $jobIds = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
        $q->whereIn('jobs.id', $jobIds);
      }
    });

    $query->when(!empty($requestdata['filter_timetype']), function ($q) use ($requestdata) {
      $this->applyTimeFilters($q, $requestdata, $requestdata['filter_timetype']);
    });

    $query->when(!empty($requestdata['job_type']), function ($q) use ($requestdata) {
      $q->whereIn('jobs.job_type', $requestdata['job_type']);
    });

    return $query->select('jobs.*', 'languages.language')
      ->orderBy('jobs.created_at', 'desc');
  }


  public function ignoreExpiring($id)
  {
    $job = Job::find($id);
    $job->ignore = 1;
    $job->save();
    return ['success', 'Changes saved'];
  }

  public function ignoreExpired($id)
  {
    $job = Job::find($id);
    $job->ignore_expired = 1;
    $job->save();
    return ['success', 'Changes saved'];
  }

  public function ignoreThrottle($id)
  {
    $throttle = Throttles::find($id);
    $throttle->ignore = 1;
    $throttle->save();
    return ['success', 'Changes saved'];
  }

  public function reopen($request)
  {
    $jobid = $request['jobid'];
    $userid = $request['userid'];

    $job = Job::find($jobid)->toArray();

    $data = $this->prepareDataArray($userid, $jobid, $job['due']);
    $datareopen = $this->prepareDataReopenArray($job['due']);

    if ($job['status'] != 'timedout') {
      $affectedRows = Job::where('id', '=', $jobid)->update($datareopen);
      $new_jobid = $jobid;
    } else {
      $affectedRows = $this->createNewJob($job, $jobid);
      $new_jobid = $affectedRows['id'];
    }

    Translator::where('job_id', $jobid)->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);
    Translator::create($data);

    if (isset($affectedRows)) {
      $this->sendNotificationByAdminCancelJob($new_jobid);
      return ["Tolk cancelled!"];
    } else {
      return ["Please try again!"];
    }
  }

  private function prepareDataArray($userid, $jobid, $due)
  {
    $now = Carbon::now();

    return [
      'created_at' => $now->toDateTimeString(),
      'will_expire_at' => TeHelper::willExpireAt($due, $now),
      'updated_at' => $now->toDateTimeString(),
      'user_id' => $userid,
      'job_id' => $jobid,
      'cancel_at' => $now,
    ];
  }

  private function prepareDataReopenArray($due)
  {
    $now = Carbon::now();

    return [
      'status' => 'pending',
      'created_at' => $now,
      'will_expire_at' => TeHelper::willExpireAt($due, $now),
    ];
  }

  private function createNewJob($job, $jobid)
  {
    $now = Carbon::now();

    $job['status'] = 'pending';
    $job['created_at'] = $now;
    $job['updated_at'] = $now;
    $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], $now);
    $job['cust_16_hour_email'] = 0;
    $job['cust_48_hour_email'] = 0;
    $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;

    return Job::create($job);
  }

  /**
   * Convert number of minutes to hour and minute variant
   * @param int $time
   * @param string $format
   * @return string
   */
  private function convertToHoursMins($time, $format = '%02dh %02dmin')
  {
    if ($time < 60) {
      return $time . 'min';
    } else if ($time == 60) {
      return '1h';
    }

    $hours = floor($time / 60);
    $minutes = ($time % 60);

    return sprintf($format, $hours, $minutes);
  }

}
