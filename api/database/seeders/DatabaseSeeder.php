<?php
namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@oswal.local');
        if (User::where('email', $email)->exists()) { $this->command?->info("Admin {$email} already exists"); return; }
        // Password: ADMIN_PASSWORD env, else from ../secrets/admin-credentials.txt, else generated and written there (chmod 600).
        $file = base_path('../secrets/admin-credentials.txt');
        $password = env('ADMIN_PASSWORD');
        if (! $password && is_file($file) && preg_match('/^ADMIN_PASSWORD=(.+)$/m', file_get_contents($file), $m)) $password = trim($m[1]);
        if (! $password) {
            $password = Str::password(20, symbols: false);
            @mkdir(dirname($file), 0700, true);
            file_put_contents($file, "ADMIN_EMAIL={$email}\nADMIN_PASSWORD={$password}\n");
            chmod($file, 0600);
        }
        User::create(['email' => $email, 'name' => 'Vibhav Pareek', 'password' => $password, 'is_admin' => true]);
        $this->command?->info("Admin seeded: {$email}");
    }
}
