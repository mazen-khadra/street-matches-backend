<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User as UserModel;
use App\Models\image as ImageModel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\ForgetPassword as ForgetPasswordMail;
use App\Http\Controllers\Player as PlayerController;

class User extends Controller
{
  public function signUp(Request $req)
  {
    $req->validate([
      'first_name' => 'required',
      'last_name' => 'required',
      'email' => 'required|email|unique:users',
      'phone_number' => 'required|unique:users',
      'password' => 'required',
      'location_long' => 'required',
      'location_lat' => 'required'
    ]);

    $newUser = new UserModel();
    $newUser->first_name = $req->first_name;
    $newUser->last_name = $req->last_name;
    $newUser->email = $req->email;
    $newUser->phone_number = $req->phone_number;
    $newUser->password = $req->password;
    $newUser->birthdate = $req->birthdate;
    $newUser->type = $req->type;
    $newUser->location_long = $req->location_long;
    $newUser->location_lat = $req->location_lat;
    $newUser->address = $req->address;
    $newUser->img_id = ImageModel::getImgIdByUrl($req->img_url);

    if ($newUser->save()) {
      if($req->type == 'PLAYER') {
        (new PlayerController())->add($req, $newUser->id, $newUser->id);
      }
      return $this->logIn($req);
    }

    return response(null, 422);
  }

  public function logIn(Request $req)
  {
    $cred = $req->validate([
      'email' => 'required|email',
      'password' => 'required'
    ]);

    if (Auth::attempt($cred)) {
      $user = Auth::user();
      $token = $user->createToken($user->first_name)->plainTextToken;
      $user->token = $token;
      $user->player = (new PlayerController())->index($user->id)->get(0);

      return response($user);
    }

    return response(["message" => "Invalid Email Or Password"], 404);
  }


  public function user(Request $req)
  {
    if (Auth::check()) {
      $user = Auth::user();
      $user->player = (new PlayerController())->index($user->id)->get(0);
      return response($user);
    }

    return response(null, 401);
  }

  public function forgetPassword(Request $req) {
    $req->validate([
      'email' => 'required|email|exists:users'
    ]);

    $user = UserModel::where('email', $req->email)->first();
    $token = str::random(5);

    DB::table('password_resets')->insert([
      'email' => $req->email,
      'token' => password_hash($token, null)
    ]);

    Mail::to($req->email)->send(new ForgetPasswordMail($user, $token));
    return response($token, 200);
  }

  public function resetPassword(Request $req) {
    $req->validate([
      'email' => 'required|email|exists:users',
      'token' => 'required',
      'password' => 'required',
    ]);

    $status = Password::reset(
      $req->only('email', 'password', 'token'),
      function ($user, $password) {
        $user->forceFill([ 'password' => $password ]);
        $user->save();
      }
    );

    $resStatus =  $status == Password::PASSWORD_RESET ? 200 : 422;
    return response(null, $resStatus);
  }

  public function updateInfo(Request $req) {
    $userFields = $req->only (
      "first_name", "last_name", "email", "password", "birthdate", "phone_number",
      "location_long", "location_lat", "address", "img_url"
    );

    $playerFields = $req->only (
      "first_name", "last_name", "birthdate", "phone_number",
      "location_long", "location_lat", "address", "dominate_foot",
      "description", "description_cn", "weight", "height",
      "year_active", "position"
    );

    if(!empty($userFields["img_url"])) {
      $userFields["img_id"] = ImageModel::getImgIdByUrl($userFields["img_url"]);
      $playerFields["img_id"] = $userFields["img_id"];
    }

    unset($userFields["img_url"]);

    $user = Auth::user();
    UserModel::query()->whereKey($user->id)->update($userFields);
    $user = $user->fresh();
    $user->player = (new PlayerController())->index($user->id)->get(0);
    if(!empty($user->player) && !empty($playerFields)) {
      $user->player->updateOrFail($playerFields);
      $user->player = (new PlayerController())->index($user->id)->get(0);
    }

    return response($user);
  }

  public function delete(Request $req) {
    $req->user()->delete();
    return response(["message" => __('accountDeleted')], 200);
  }

}
