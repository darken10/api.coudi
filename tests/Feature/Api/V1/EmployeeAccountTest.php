<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\EmployeeInvitationEvent;
use App\Models\User;
use App\Services\Messaging\MessageOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const EMPLOYEE_PHONE = '+22670123456';

/**
 * @return array{owner: User, company: Company, employee: Employee}
 */
function employeeWorkshopFixture(?string $phone = EMPLOYEE_PHONE, string $companyName = 'Atelier Fatou'): array
{
    $owner = User::factory()->create();

    $company = new Company;
    $company->fill(['name' => $companyName, 'status' => Company::STATUS_ACTIVE]);
    $company->save();

    $company->members()->attach($owner->id, ['role' => Company::ROLE_OWNER, 'joined_at' => now()]);

    $employee = Employee::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Awa Traoré',
        'role' => 'tailleur',
        'phone' => $phone,
        'status' => 'active',
    ]);

    Sanctum::actingAs($owner);

    return ['owner' => $owner, 'company' => $company, 'employee' => $employee];
}

/** @param array<string, mixed> $payload */
function inviteEmployee(Company $company, Employee $employee, array $payload = ['channel' => 'sms']): Illuminate\Testing\TestResponse
{
    return test()->postJson(
        "/api/v1/employees/{$employee->id}/invitations",
        $payload,
        ['X-Company-Id' => $company->getKey()],
    );
}

/** Dernier code reçu par le numéro, lu dans la boîte d'envoi de test. */
function lastCode(string $channel = 'sms', string $phone = EMPLOYEE_PHONE): string
{
    $message = app(MessageOutbox::class)->last($channel, $phone);
    expect($message)->not->toBeNull();
    preg_match('/\b(\d{6})\b/', (string) $message, $matches);

    return $matches[1];
}

function verifyCode(string $code, string $phone = EMPLOYEE_PHONE): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/activation/verify', ['phone' => $phone, 'code' => $code, 'device_id' => 'device-awa']);
}

function completeActivation(string $ticket, string $password = 'motdepasse123'): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/activation/complete', [
        'ticket' => $ticket,
        'password' => $password,
        'password_confirmation' => $password,
    ]);
}

beforeEach(function (): void {
    app(MessageOutbox::class)->flush();
    RateLimiter::clear('activation-verify:'.EMPLOYEE_PHONE);
});

describe('Création et envoi de l’invitation', function (): void {
    it('crée l’invitation, envoie un code à 6 chiffres par SMS et ne stocke que son hash', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();

        $response = inviteEmployee($company, $employee)
            ->assertCreated()
            ->assertJsonPath('data.invitation.status', EmployeeInvitation::STATUS_AWAITING_ACTIVATION)
            ->assertJsonPath('data.invitation.send_status', 'sent')
            ->assertJsonPath('data.invitation.channel', 'sms')
            ->assertJsonPath('data.invitation.phone', EMPLOYEE_PHONE)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.code');

        $code = lastCode();
        $invitation = EmployeeInvitation::query()->findOrFail($response->json('data.invitation.id'));

        expect($code)->toMatch('/^\d{6}$/')
            ->and($invitation->code_hash)->not->toBeNull()->not->toContain($code)
            ->and($invitation->expires_at->diffInHours(now(), true))->toBeGreaterThan(23.9)->toBeLessThanOrEqual(24.0)
            ->and(app(MessageOutbox::class)->last('sms', EMPLOYEE_PHONE))->toContain('Bonjour Awa')->toContain('24 heures');

        $this->assertDatabaseHas('users', ['phone' => EMPLOYEE_PHONE, 'status' => User::STATUS_PENDING_ACTIVATION]);
        expect(json_encode($response->json()))->not->toContain($code);
    });

    it('envoie le code par WhatsApp quand ce canal est choisi', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();

        inviteEmployee($company, $employee, ['channel' => 'whatsapp'])
            ->assertCreated()
            ->assertJsonPath('data.invitation.channel', 'whatsapp');

        expect(lastCode('whatsapp'))->toMatch('/^\d{6}$/')
            ->and(app(MessageOutbox::class)->count('sms', EMPLOYEE_PHONE))->toBe(0);
    });

    it('normalise un numéro saisi sans indicatif au format +226', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture(null);

        inviteEmployee($company, $employee, ['phone' => '70 12 34 56', 'channel' => 'sms'])
            ->assertCreated()
            ->assertJsonPath('data.invitation.phone', '+22670123456')
            ->assertJsonPath('data.invitation.phone_display', '+226 70 12 34 56');
    });

    it('refuse une invitation sans numéro de téléphone', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture(null);

        inviteEmployee($company, $employee)->assertStatus(422)->assertJsonValidationErrors('phone');
        expect(EmployeeInvitation::query()->count())->toBe(0);
    });

    it('refuse un numéro invalide', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture(null);

        inviteEmployee($company, $employee, ['phone' => '12', 'channel' => 'sms'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
    });

    it('refuse un numéro déjà porté par un autre compte COUDI', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        User::factory()->create(['phone' => EMPLOYEE_PHONE]);

        inviteEmployee($company, $employee)->assertStatus(422)->assertJsonValidationErrors('phone');
    });

    it('signale un canal non configuré au lieu de simuler l’envoi', function (): void {
        config(['otp.providers.whatsapp.driver' => 'http', 'otp.providers.whatsapp.url' => null]);
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();

        $this->getJson('/api/v1/employee-invitations/channels', ['X-Company-Id' => $company->getKey()])
            ->assertOk()
            ->assertJsonPath('data.whatsapp.available', false)
            ->assertJsonPath('data.sms.available', true);

        inviteEmployee($company, $employee, ['channel' => 'whatsapp'])
            ->assertStatus(422)->assertJsonValidationErrors('channel');
    });

    it('enregistre l’échec du fournisseur SMS sans prétendre que le code est parti', function (): void {
        config(['otp.providers.sms' => ['driver' => 'http', 'url' => 'https://sms.test/send', 'token' => 'secret']]);
        Http::fake(['sms.test/*' => Http::response(['error' => 'quota'], 500)]);
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();

        $response = inviteEmployee($company, $employee)
            ->assertCreated()
            ->assertJsonPath('data.invitation.status', EmployeeInvitation::STATUS_SEND_FAILED)
            ->assertJsonPath('data.invitation.send_error', 'provider_rejected')
            ->assertJsonPath('data.invitation.sent_at', null);

        expect($response->json('message'))->toContain('n’a pas pu être envoyé');
        $this->assertDatabaseHas('employee_invitation_events', ['type' => 'send_failed', 'channel' => 'sms']);
    });

    it('enregistre l’échec du fournisseur WhatsApp injoignable', function (): void {
        config(['otp.providers.whatsapp' => ['driver' => 'http', 'url' => 'https://wa.test/send', 'token' => 'secret']]);
        Http::fake(fn () => throw new ConnectionException('timeout'));
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();

        inviteEmployee($company, $employee, ['channel' => 'whatsapp'])
            ->assertCreated()
            ->assertJsonPath('data.invitation.status', EmployeeInvitation::STATUS_SEND_FAILED)
            ->assertJsonPath('data.invitation.send_error', 'provider_unreachable');
    });

    it('envoie le message attendu à la passerelle HTTP et garde l’identifiant du fournisseur', function (): void {
        config(['otp.providers.sms' => ['driver' => 'http', 'url' => 'https://sms.test/send', 'token' => 'secret', 'from' => 'COUDI']]);
        Http::fake(['sms.test/*' => Http::response(['id' => 'msg-42'], 200)]);
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();

        inviteEmployee($company, $employee)->assertCreated()->assertJsonPath('data.invitation.send_status', 'sent');

        Http::assertSent(fn ($request) => $request['to'] === EMPLOYEE_PHONE
            && $request['from'] === 'COUDI'
            && preg_match('/\d{6}/', $request['message']) === 1
            && $request->hasHeader('Authorization', 'Bearer secret'));
        expect(EmployeeInvitation::query()->firstOrFail()->provider_message_id)->toBe('msg-42');
    });
});

describe('Activation depuis le téléphone de l’employé', function (): void {
    it('active le compte : code, puis mot de passe obligatoire, puis session', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee)->assertCreated();

        // Le patron n'est plus connecté : on est sur le téléphone de l'employé.
        app('auth')->forgetGuards();

        $verified = verifyCode(lastCode(), '70 12 34 56')
            ->assertOk()
            ->assertJsonPath('data.employee_name', 'Awa Traoré')
            ->assertJsonPath('data.company_name', 'Atelier Fatou')
            ->assertJsonMissingPath('data.access_token');

        // Avant le mot de passe : aucune connexion possible.
        $blocked = $this->postJson('/api/v1/login', ['login' => EMPLOYEE_PHONE, 'password' => 'motdepasse123']);
        expect($blocked->status())->toBeIn([401, 403]);
        expect(EmployeeInvitation::query()->firstOrFail()->status())->toBe(EmployeeInvitation::STATUS_PASSWORD_REQUIRED);

        $completed = completeActivation($verified->json('data.ticket'))->assertOk();
        expect($completed->json('data.access_token'))->toBeString()
            ->and($completed->json('data.refresh_token'))->toBeString();

        $user = User::query()->where('phone', EMPLOYEE_PHONE)->firstOrFail();
        $invitation = EmployeeInvitation::query()->firstOrFail();

        expect($user->status)->toBe(User::STATUS_ACTIVE)
            ->and($user->password_set_at)->not->toBeNull()
            ->and($user->phone_verified_at)->not->toBeNull()
            ->and($user->roleIn($company))->toBe(Company::ROLE_TAILOR)
            ->and($employee->refresh()->user_id)->toBe($user->id)
            ->and($invitation->status())->toBe(EmployeeInvitation::STATUS_ACTIVATED)
            ->and($invitation->activation_device_id)->toBe('device-awa');

        // Connexion ordinaire ensuite, numéro saisi au format local.
        $this->postJson('/api/v1/login', ['login' => '70 12 34 56', 'password' => 'motdepasse123'])->assertOk();
    });

    it('refuse un code incorrect et compte les essais', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);
        $code = lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        verifyCode($wrong)->assertStatus(422)->assertJsonValidationErrors('code');
        expect(EmployeeInvitation::query()->firstOrFail()->attempts)->toBe(1);

        verifyCode($code)->assertOk();
    });

    it('bloque le code après le nombre maximal d’essais, même ensuite avec le bon code', function (): void {
        config(['koudi.invitation.verify_per_phone' => 50]);
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);
        $code = lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 5) as $_) {
            verifyCode($wrong)->assertStatus(422);
        }

        verifyCode($code)->assertStatus(422);
        expect(EmployeeInvitation::query()->firstOrFail()->status())->toBe(EmployeeInvitation::STATUS_BLOCKED);
        $this->assertDatabaseHas('employee_invitation_events', ['type' => 'code_blocked']);
    });

    it('refuse un code expiré après 24 heures', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);
        $code = lastCode();

        $this->travel(24)->hours();
        $this->travel(1)->minutes();

        verifyCode($code)->assertStatus(422);
        expect(EmployeeInvitation::query()->firstOrFail()->status())->toBe(EmployeeInvitation::STATUS_EXPIRED);
        $this->assertDatabaseHas('employee_invitation_events', ['type' => 'code_expired']);
    });

    it('accepte encore le code juste avant 24 heures', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);
        $code = lastCode();

        $this->travel(23)->hours();
        $this->travel(59)->minutes();

        verifyCode($code)->assertOk();
    });

    it('refuse de réutiliser un code déjà consommé', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);
        $code = lastCode();

        verifyCode($code)->assertOk();
        verifyCode($code)->assertStatus(422);
    });

    it('ne valide qu’une fois deux saisies concurrentes du même code', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);
        $code = lastCode();

        $first = verifyCode($code);
        $second = verifyCode($code);

        expect([$first->status(), $second->status()])->toBe([200, 422])
            ->and(EmployeeInvitationEvent::query()->where('type', 'code_verified')->count())->toBe(1);
    });

    it('ne crée aucune session avec un ticket invalide, déjà utilisé ou expiré', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);

        completeActivation(str_repeat('a', 64))->assertStatus(422)->assertJsonValidationErrors('ticket');

        $ticket = verifyCode(lastCode())->json('data.ticket');
        completeActivation($ticket)->assertOk();
        completeActivation($ticket)->assertStatus(422);

        // Nouvel employé : ticket laissé expirer.
        $other = Employee::query()->create([
            'company_id' => $company->getKey(), 'name' => 'Ali', 'phone' => '+22676000001', 'status' => 'active',
        ]);
        Sanctum::actingAs($company->members()->firstOrFail());
        inviteEmployee($company, $other)->assertCreated();
        $ticket2 = verifyCode(lastCode('sms', '+22676000001'), '+22676000001')->json('data.ticket');
        $this->travel(31)->minutes();
        completeActivation($ticket2)->assertStatus(422);
        expect(User::query()->where('phone', '+22676000001')->value('status'))->toBe(User::STATUS_PENDING_ACTIVATION);
    });

    it('limite les essais par numéro, toutes invitations confondues', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);

        foreach (range(1, 5) as $_) {
            verifyCode('999999');
        }

        verifyCode(lastCode())->assertStatus(422)
            ->assertJsonPath('errors.code.0', fn (string $message) => str_contains($message, 'Trop d’essais'));
    });

    it('répond de la même façon pour un numéro jamais invité', function (): void {
        employeeWorkshopFixture();

        $unknown = verifyCode('123456', '+22671999999')->assertStatus(422);
        expect($unknown->json('errors.code.0'))->toContain('Numéro ou code incorrect');
    });

    it('demande le mot de passe avec confirmation', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);
        $ticket = verifyCode(lastCode())->json('data.ticket');

        $this->postJson('/api/v1/auth/activation/complete', [
            'ticket' => $ticket, 'password' => 'motdepasse123', 'password_confirmation' => 'autre',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/activation/complete', [
            'ticket' => $ticket, 'password' => 'court', 'password_confirmation' => 'court',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        completeActivation($ticket)->assertOk();
    });
});

describe('Renvoi et suivi par le chef d’atelier', function (): void {
    it('renvoie un nouveau code et invalide l’ancien', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);
        $old = lastCode();

        $this->travel(61)->seconds();
        inviteEmployee($company, $employee, [])->assertCreated();
        $new = lastCode();

        expect(EmployeeInvitation::query()->whereNotNull('revoked_at')->count())->toBe(1);

        if ($old !== $new) {
            verifyCode($old)->assertStatus(422);
        }
        verifyCode($new)->assertOk();
        $this->assertDatabaseHas('employee_invitation_events', ['type' => 'resent']);
    });

    it('permet de changer de numéro et de canal au renvoi', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);
        $this->travel(61)->seconds();

        inviteEmployee($company, $employee, ['phone' => '+226 76 11 22 33', 'channel' => 'whatsapp'])
            ->assertCreated()
            ->assertJsonPath('data.invitation.phone', '+22676112233')
            ->assertJsonPath('data.invitation.channel', 'whatsapp');

        expect(User::query()->where('phone', '+22676112233')->exists())->toBeTrue()
            ->and(User::query()->count())->toBe(2); // le patron + un seul compte employé
    });

    it('impose un délai entre deux envois et un plafond sur 24 heures', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee)->assertCreated();
        inviteEmployee($company, $employee)->assertStatus(422)->assertJsonValidationErrors('employee');

        foreach (range(2, 5) as $_) {
            $this->travel(61)->seconds();
            inviteEmployee($company, $employee)->assertCreated();
        }

        $this->travel(61)->seconds();
        $limited = inviteEmployee($company, $employee)->assertStatus(422);
        expect($limited->json('errors.employee.0'))->toContain('Limite atteinte');
    });

    it('refuse de renvoyer un code à un compte déjà activé', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);
        completeActivation(verifyCode(lastCode())->json('data.ticket'))->assertOk();

        Sanctum::actingAs($company->members()->wherePivot('role', Company::ROLE_OWNER)->firstOrFail());
        $this->travel(61)->seconds();
        inviteEmployee($company, $employee)->assertStatus(422)->assertJsonValidationErrors('employee');
    });

    it('suit le statut de chaque employé dans l’écran de suivi', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);

        $this->getJson('/api/v1/employee-invitations', ['X-Company-Id' => $company->getKey()])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_name', 'Awa Traoré')
            ->assertJsonPath('data.0.status', EmployeeInvitation::STATUS_AWAITING_ACTIVATION);

        verifyCode(lastCode())->assertOk();

        $this->getJson("/api/v1/employees/{$employee->id}/invitations", ['X-Company-Id' => $company->getKey()])
            ->assertOk()
            ->assertJsonPath('data.items.0.status', EmployeeInvitation::STATUS_PASSWORD_REQUIRED);
    });

    it('rend le code inutilisable après révocation', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        $id = inviteEmployee($company, $employee)->json('data.invitation.id');

        $this->deleteJson("/api/v1/employees/{$employee->id}/invitations/{$id}", [], ['X-Company-Id' => $company->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', EmployeeInvitation::STATUS_REVOKED);

        verifyCode(lastCode())->assertStatus(422);
    });

    it('ne journalise jamais le code', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);
        $code = lastCode();
        verifyCode($code === '000000' ? '111111' : '000000');
        verifyCode($code);

        $dump = EmployeeInvitationEvent::query()->get()->toJson();
        expect($dump)->not->toContain($code)
            ->and(EmployeeInvitationEvent::query()->pluck('type')->all())
            ->toContain('created', 'sent', 'code_failed', 'code_verified');
    });
});

describe('Isolation et autorisations', function (): void {
    it('ne laisse pas un patron inviter l’employé d’un autre atelier', function (): void {
        ['company' => $company] = employeeWorkshopFixture();

        $autre = new Company;
        $autre->fill(['name' => 'Atelier voisin']);
        $autre->save();

        $intrus = Employee::query()->create([
            'company_id' => $autre->getKey(), 'name' => 'Inconnu', 'phone' => '+22671000000', 'status' => 'active',
        ]);

        inviteEmployee($company, $intrus)->assertNotFound();
    });

    it('rattache l’employé activé à son seul atelier', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        ['company' => $other] = employeeWorkshopFixture('+22671000000', 'Atelier voisin');

        Sanctum::actingAs($company->members()->firstOrFail());
        inviteEmployee($company, $employee);
        $user = User::query()->findOrFail(completeActivation(verifyCode(lastCode())->json('data.ticket'))->json('data.user.id'));

        expect($user->roleIn($company))->toBe(Company::ROLE_TAILOR)
            ->and($user->roleIn($other))->toBeNull();

        $this->getJson('/api/v1/employee-invitations', ['X-Company-Id' => $other->getKey()])->assertForbidden();
    });

    it('n’affiche dans le suivi que les invitations de l’atelier courant', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        inviteEmployee($company, $employee);

        ['company' => $other] = employeeWorkshopFixture('+22671000000', 'Atelier voisin');

        $this->getJson('/api/v1/employee-invitations', ['X-Company-Id' => $other->getKey()])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('interdit l’invitation à un rôle lecteur', function (): void {
        ['owner' => $owner, 'company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        $company->members()->updateExistingPivot($owner->id, ['role' => Company::ROLE_TAILOR]);

        inviteEmployee($company, $employee)->assertForbidden();
    });

    it('refuse d’inviter un employé désactivé', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();
        $employee->forceFill(['status' => 'inactive'])->save();

        inviteEmployee($company, $employee)->assertStatus(422)->assertJsonValidationErrors('employee');
    });

    it('refuse de connecter un compte en attente d’activation', function (): void {
        employeeWorkshopFixture();

        User::query()->create([
            'name' => 'Awa', 'phone' => '+22670999999', 'password' => 'motdepasse123',
            'status' => User::STATUS_PENDING_ACTIVATION,
        ]);

        $this->postJson('/api/v1/login', ['login' => '+22670999999', 'password' => 'motdepasse123'])->assertForbidden();
    });
});
