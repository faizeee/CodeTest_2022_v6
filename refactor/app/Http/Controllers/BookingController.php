<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

  /**
   * BookingController constructor.
   * @param BookingRepository $repository
   */
  public function __construct(protected BookingRepository $repository)
  {
  }

  /**
   * @param Request $request
   * @return mixed
   */
  public function index(Request $request)
  {
    $user_id = $request->get('user_id');
    $response = null;
    if ($user_id) {
      $response = $this->repository->getUsersJobs($user_id);
    } elseif ($this->isAdmin($request)) {
      $response = $this->repository->getAll($request);
    }

    return response($response);
  }


  private function isAdmin(Request $request): bool
  {
    return in_array($request->__authenticatedUser->user_type, [env('ADMIN_ROLE_ID'), env('SUPERADMIN_ROLE_ID')]);
  }

  /**
   * @param $id
   * @return mixed
   */
  public function show($id)
  {
    $job = $this->repository->with('translatorJobRel.user')->find($id);
    return response($job);
  }

  /**
   * @param Request $request
   * @return mixed
   */
  public function store(Request $request)
  {
    $response = $this->repository->store($request->__authenticatedUser, $request->all());
    return response($response);

  }

  /**
   * @param $id
   * @param Request $request
   * @return mixed
   */
  public function update($id, Request $request)
  {
    $data = $request->all();
    $current_user = $request->__authenticatedUser;
    $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $current_user);

    return response($response);
  }

  /**
   * @param Request $request
   * @return mixed
   */
  public function immediateJobEmail(Request $request)
  {
    $response = $this->repository->storeJobEmail($request->all());
    return response($response);
  }

  /**
   * @param Request $request
   * @return mixed
   */
  public function getHistory(Request $request)
  {
    $user_id = $request->get('user_id');
    if (!$user_id) {
      return null;
    }
    $response = $this->repository->getUsersJobsHistory($user_id, $request);
    return response($response);;
  }

  /**
   * @param Request $request
   * @return mixed
   */
  public function acceptJob(Request $request)
  {
    $response = $this->repository->acceptJob($request->all(), $request->__authenticatedUser);
    return response($response);
  }

  public function acceptJobWithId(Request $request)
  {
    $data = $request->get('job_id');
    $response = $this->repository->acceptJobWithId($data, $request->__authenticatedUser);
    return response($response);
  }

  /**
   * @param Request $request
   * @return mixed
   */
  public function cancelJob(Request $request)
  {
    $response = $this->repository->cancelJobAjax($request->all(), $request->__authenticatedUser);
    return response($response);
  }

  /**
   * @param Request $request
   * @return mixed
   */
  public function endJob(Request $request)
  {
    $response = $this->repository->endJob($request->all());
    return response($response);

  }

  public function customerNotCall(Request $request)
  {
    $response = $this->repository->customerNotCall($request->all());
    return response($response);
  }

  /**
   * @param Request $request
   * @return mixed
   */
  public function getPotentialJobs(Request $request)
  {
    $response = $this->repository->getPotentialJobs($request->__authenticatedUser);
    return response($response);
  }

  public function distanceFeed(Request $request)
  {
    $data = $request->all();
    $job_id = $data['jobid'] ?? "";
    if ($data['flagged'] == 'true' && empty($data['admincomment'])) {
      return "Please, add comment";
    }

    if (!empty($job_id)) {
      $this->updateDistance($job_id, $data);
      $this->updateJob($job_id, $data);
    }

    return response('Record updated!');


  }

  /**
   * @param $job_id
   * @param array $data
   * @return void
   */
  private function updateDistance($job_id, array $data)
  {
    $distance = $data['distance'] ?? "";
    $time = $data['time'] ?? "";

    if (!($time || $distance)) {
      return;
    }

    Distance::where('job_id', '=', $job_id)->update(array('distance' => $distance, 'time' => $time));
  }

  /**
   * @param $job_id
   * @param array $data
   * @return void
   */
  private function updateJob($job_id, array $data): void
  {
    $session = $data['session_time'] ?? "";
    $manually_handled = $data['manually_handled'] == 'true' ? 'yes' : 'no';
    $by_admin = $data['by_admin'] == 'true' ? 'yes' : 'no';
    $flagged = $data['flagged'] == 'true' ? 'yes' : "NO";
    $admincomment = $data['admincomment'] ?? "";

    if (!($admincomment || $session || $flagged || $manually_handled || $by_admin)) {
      return;
    }

    Job::where('id', '=', $job_id)->update(array('admin_comments' => $admincomment, 'flagged' => $flagged, 'session_time' => $session, 'manually_handled' => $manually_handled, 'by_admin' => $by_admin));

  }

  public function reopen(Request $request)
  {
    $response = $this->repository->reopen($request->all());
    return response($response);
  }

  public function resendNotifications(Request $request)
  {
    $data = $request->all();
    $job = $this->repository->find($data['jobid']);
    $job_data = $this->repository->jobToData($job);
    $this->repository->sendNotificationTranslator($job, $job_data, '*');

    return response(['success' => 'Push sent']);
  }

  /**
   * Sends SMS to Translator
   * @param Request $request
   * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
   */
  public function resendSMSNotifications(Request $request)
  {
    $data = $request->all();
    $job = $this->repository->find($data['jobid']);
    try {
      $this->repository->sendSMSNotificationToTranslator($job);
      return response(['success' => 'SMS sent']);
    } catch (\Exception $e) {
      return response(['success' => $e->getMessage()]);
    }
  }

}
