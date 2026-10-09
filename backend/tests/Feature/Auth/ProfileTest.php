<?php

namespace Tests\Feature\Auth;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The Profile page's data: personal details, address, account information, password change time and the picture. */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = User::create(['name' => 'Dinesh Ghimire', 'email' => 'dinesh@example.com', 'password' => 'password']);
        Sanctum::actingAs($this->user);
    }

    private const MUTATION = 'mutation ($first_name: String, $last_name: String, $email: String, $username: String, $phone: String, $gender: String,
        $date_of_birth: String, $occupation: String, $bio: String, $timezone: String, $country: String, $province: String, $city: String,
        $street_address: String, $postal_code: String) {
        updateProfile(first_name: $first_name, last_name: $last_name, email: $email, username: $username, phone: $phone, gender: $gender,
            date_of_birth: $date_of_birth, occupation: $occupation, bio: $bio, timezone: $timezone, country: $country, province: $province,
            city: $city, street_address: $street_address, postal_code: $postal_code) {
            name email username user_code profile { first_name last_name phone gender date_of_birth occupation bio timezone country province city street_address postal_code avatar_url }
        }
    }';

    private function form(array $over = []): array
    {
        return array_merge([
            'first_name' => 'Dinesh', 'last_name' => 'Ghimire', 'email' => 'dinesh@example.com', 'username' => 'dineshghimire',
            'phone' => '+977 9800000000', 'gender' => 'male', 'date_of_birth' => '1998-05-15', 'occupation' => 'Software Developer',
            'bio' => 'Builds things.', 'timezone' => 'Asia/Kathmandu', 'country' => 'Nepal', 'province' => 'Bagmati', 'city' => 'Kathmandu',
            'street_address' => 'Example Street', 'postal_code' => '44600',
        ], $over);
    }

    public function test_the_whole_profile_is_saved_and_read_back(): void
    {
        $profile = $this->graphQL(self::MUTATION, $this->form())->assertJsonMissingPath('errors')->json('data.updateProfile');

        $this->assertSame('Dinesh Ghimire', $profile['name']); // the app-wide display name is first + last
        $this->assertSame('dineshghimire', $profile['username']);
        $this->assertSame('USR-1001', $profile['user_code']);
        $this->assertSame('Nepal', $profile['profile']['country']);
        $this->assertSame('1998-05-15', $profile['profile']['date_of_birth']);
        $this->assertSame('Asia/Kathmandu', $profile['profile']['timezone']);
        $this->assertSame('44600', $profile['profile']['postal_code']);

        $this->assertSame('Dinesh Ghimire', $this->user->fresh()->name);

        $me = $this->graphQL('{ me { name username profile { first_name last_name phone gender occupation bio city street_address } } }')->json('data.me');
        $this->assertSame('Ghimire', $me['profile']['last_name']);
        $this->assertSame('male', $me['profile']['gender']);
        $this->assertSame('Example Street', $me['profile']['street_address']);
    }

    public function test_an_account_with_only_a_single_name_has_it_split_for_the_form(): void
    {
        $me = $this->graphQL('{ me { name profile { first_name last_name avatar_url phone } } }')->json('data.me');

        $this->assertSame('Dinesh', $me['profile']['first_name']);
        $this->assertSame('Ghimire', $me['profile']['last_name']);
        $this->assertNull($me['profile']['avatar_url']);
        $this->assertNull($me['profile']['phone']);
    }

    public function test_emptied_optional_fields_are_stored_as_nothing(): void
    {
        $this->graphQL(self::MUTATION, $this->form());
        $this->graphQL(self::MUTATION, $this->form(['phone' => '  ', 'bio' => '', 'last_name' => '', 'username' => '', 'city' => '']))->assertJsonMissingPath('errors');

        $user = $this->user->fresh();
        $this->assertSame('Dinesh', $user->name); // no trailing space with no last name
        $this->assertNull($user->username);
        $this->assertNull($user->profile->phone);
        $this->assertNull($user->profile->bio);
        $this->assertNull($user->profile->last_name);
        $this->assertNull($user->profile->city);
    }

    public function test_validation_rejects_bad_input_with_readable_messages(): void
    {
        User::create(['name' => 'Other', 'email' => 'taken@example.com', 'username' => 'taken', 'password' => 'password']);

        $cases = [
            'email' => [['email' => 'taken@example.com'], 'email'],
            'username taken' => [['username' => 'taken'], 'username'],
            'username shape' => [['username' => '-bad name'], 'username'],
            'phone' => [['phone' => 'call me'], 'phone'],
            'gender' => [['gender' => 'robot'], 'gender'],
            'gender no longer offered' => [['gender' => 'other'], 'gender'],
            'future birthday' => [['date_of_birth' => now()->addDay()->toDateString()], 'date_of_birth'],
            'timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
            'first name' => [['first_name' => ''], 'first_name'],
            'bio too long' => [['bio' => str_repeat('x', 501)], 'bio'],
        ];

        foreach ($cases as $label => [$over, $field]) {
            $response = $this->graphQL(self::MUTATION, $this->form($over));
            $this->assertArrayHasKey($field, $response->json('errors.0.extensions.validation') ?? [], $label);
        }

        $this->assertSame('dinesh@example.com', $this->user->fresh()->email); // nothing was saved
    }

    public function test_you_can_keep_your_own_email_and_username_when_saving(): void
    {
        $this->graphQL(self::MUTATION, $this->form())->assertJsonMissingPath('errors');
        $this->graphQL(self::MUTATION, $this->form(['bio' => 'Changed']))->assertJsonMissingPath('errors');
    }

    public function test_the_account_information_is_served(): void
    {
        LoginHistory::create(['user_id' => $this->user->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'logged_in_at' => '2026-10-08 10:00:00']);
        LoginHistory::create(['user_id' => $this->user->id, 'ip_address' => '1.1.1.2', 'user_agent' => 'x', 'logged_in_at' => '2026-10-09 09:30:00']);

        $me = $this->graphQL('{ me { user_code email_verified_at password_changed_at last_login_at created_at } }')->json('data.me');

        $this->assertSame('USR-1001', $me['user_code']);
        $this->assertNull($me['password_changed_at']); // never changed
        $this->assertStringStartsWith('2026-10-09T09:30:00', (string) $me['last_login_at']); // an ISO timestamp the browser can place in any time zone
        $this->assertNotNull($me['created_at']);
    }

    public function test_changing_the_password_records_when(): void
    {
        $this->graphQL('mutation { updatePassword(current_password: "password", password: "a-new-Password-1", password_confirmation: "a-new-Password-1") }')
            ->assertJsonMissingPath('errors');

        $this->assertNotNull($this->user->fresh()->password_changed_at);
        $this->assertEqualsWithDelta(0, now()->diffInSeconds($this->user->fresh()->password_changed_at), 5);
    }

    // ----------------------------------------------------------------- the picture

    public function test_a_picture_is_cropped_to_a_square_stored_and_served_publicly(): void
    {
        $response = $this->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 800, 400)], ['Accept' => 'application/json'])->assertOk();

        $url = $response->json('avatar_url');
        $this->assertMatchesRegularExpression('#/avatars/[a-f0-9]{40}\.jpg$#', $url);

        $file = basename($url);
        Storage::disk('local')->assertExists('avatars/'.$file);
        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get('avatars/'.$file));
        $this->assertSame([256, 256], [$width, $height]);

        $this->assertSame($url, $this->graphQL('{ me { profile { avatar_url } } }')->json('data.me.profile.avatar_url'));

        // Anyone with the address can load it (an <img> tag cannot send the login token).
        $this->app['auth']->forgetGuards();
        $this->get('/avatars/'.$file)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_new_picture_replaces_and_deletes_the_old_one(): void
    {
        $first = basename($this->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 300, 300)], ['Accept' => 'application/json'])->json('avatar_url'));
        $second = basename($this->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('b.jpg', 300, 300)], ['Accept' => 'application/json'])->json('avatar_url'));

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing('avatars/'.$first);
        Storage::disk('local')->assertExists('avatars/'.$second);
    }

    public function test_the_picture_can_be_removed(): void
    {
        $file = basename($this->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 300, 300)], ['Accept' => 'application/json'])->json('avatar_url'));

        $this->deleteJson('/api/profile/avatar')->assertOk()->assertJson(['avatar_url' => null]);

        Storage::disk('local')->assertMissing('avatars/'.$file);
        $this->assertNull($this->graphQL('{ me { profile { avatar_url } } }')->json('data.me.profile.avatar_url'));
    }

    public function test_only_pictures_are_accepted(): void
    {
        $this->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('huge.jpg')->size(4000)], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_the_picture_needs_a_login_and_only_exact_file_names_are_served(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/profile/avatar')->assertStatus(401);

        $this->get('/avatars/'.str_repeat('a', 40).'.jpg')->assertNotFound();      // right shape, no such file
        $this->get('/avatars/../../.env')->assertNotFound();                          // not a name we serve
        $this->get('/avatars/'.str_repeat('a', 39).'.jpg')->assertNotFound();        // wrong length
    }
}
