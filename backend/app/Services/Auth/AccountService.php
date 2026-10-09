<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The signed-in person's profile: what the Profile page shows and saves, and the profile picture.
 */
class AccountService
{
    private const AVATAR_SIZE = 256;

    /** The user as the API serves it: account fields, a "USR-1001" code, last login and the profile (with the picture URL). */
    public function present(User $user): array
    {
        $profile = $user->profile()->first(); // read fresh: the user object may hold an older (or no) profile
        // Accounts made before profiles existed only have a single name: split it for the form.
        [$first, $last] = $profile?->first_name !== null ? [$profile->first_name, $profile->last_name] : $this->splitName($user->name);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'user_code' => 'USR-'.(1000 + $user->id),
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            // Through the model so it is a proper timestamp (with its time zone), like every other time we send.
            'last_login_at' => $user->loginHistories()->latest('logged_in_at')->first()?->logged_in_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'profile' => [
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $profile?->phone,
                'gender' => $profile?->gender,
                'date_of_birth' => $profile?->date_of_birth?->toDateString(),
                'occupation' => $profile?->occupation,
                'bio' => $profile?->bio,
                'timezone' => $profile?->timezone,
                'avatar_url' => $profile?->avatar ? url('avatars/'.$profile->avatar) : null,
                'country' => $profile?->country,
                'province' => $profile?->province,
                'city' => $profile?->city,
                'street_address' => $profile?->street_address,
                'postal_code' => $profile?->postal_code,
            ],
        ];
    }

    /**
     * Saves the validated profile form. The display name used across the app is the first and last name together.
     *
     * @param  array<string, mixed>  $data  UpdateProfileRequest's validated input
     */
    public function save(User $user, array $data): User
    {
        $first = trim((string) $data['first_name']);
        $last = trim((string) ($data['last_name'] ?? ''));

        $user->update([
            'name' => trim($first.' '.$last),
            'email' => $data['email'],
            'username' => ($data['username'] ?? '') !== '' ? $data['username'] : null,
        ]);

        $fields = array_intersect_key($data, array_flip([
            'phone', 'gender', 'date_of_birth', 'occupation', 'bio', 'timezone', 'country', 'province', 'city', 'street_address', 'postal_code',
        ]));

        // An emptied field is stored as nothing, not as an empty string.
        $fields = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v), $fields);

        UserProfile::updateOrCreate(['user_id' => $user->id], ['first_name' => $first, 'last_name' => $last !== '' ? $last : null, ...$fields]);

        return $user->fresh();
    }

    /** Stores the picture cropped to a square (replacing the old one) and returns its public URL. */
    public function storeAvatar(User $user, UploadedFile $file): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw new RuntimeException('That file is not an image this server can read.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);
        $square = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);
        // A transparent PNG would turn black as a JPEG: lay it on white first.
        imagefill($square, 0, 0, imagecolorallocate($square, 255, 255, 255));
        imagecopyresampled($square, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::AVATAR_SIZE, self::AVATAR_SIZE, $side, $side);

        ob_start();
        imagejpeg($square, null, 85);
        $jpeg = (string) ob_get_clean();

        $name = bin2hex(random_bytes(20)).'.jpg';
        Storage::disk('local')->put('avatars/'.$name, $jpeg);

        $this->deleteAvatarFile($user->profile()->value('avatar'));
        UserProfile::updateOrCreate(['user_id' => $user->id], ['avatar' => $name]);

        return url('avatars/'.$name);
    }

    public function removeAvatar(User $user): void
    {
        $this->deleteAvatarFile($user->profile()->value('avatar'));
        $user->profile()->update(['avatar' => null]);
    }

    /** @return array{0: string, 1: ?string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? '', $parts[1] ?? null];
    }

    private function deleteAvatarFile(?string $file): void
    {
        if ($file !== null && preg_match('/^[a-f0-9]{40}\.jpg$/', $file)) {
            Storage::disk('local')->delete('avatars/'.$file);
        }
    }
}
