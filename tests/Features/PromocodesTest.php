<?php

use Carbon\Carbon;
use Illuminate\Support\Str;
use Zorb\Promocodes\Exceptions\{
    PromocodeAlreadyUsedByUserException,
    PromocodeBoundToOtherUserException,
    PromocodeDoesNotExistException,
    PromocodeExpiredException,
    PromocodeNoUsagesLeftException,
    UserHasNoAppliesPromocodeTrait,
    UserRequiredToAcceptPromocode
};
use Zorb\Promocodes\Tests\Models\{User, UserWithoutTrait};
use Zorb\Promocodes\Contracts\PromocodeContract;
use Zorb\Promocodes\Facades\Promocodes;
use Zorb\Promocodes\Models\Promocode;
use Zorb\Promocodes\Models\PromocodeUser;

it('should set code to variable, but not promocode', function () {
    $code = 'FOO-BAR';
    $promocode = Promocodes::code($code);

    $class = new ReflectionClass($promocode);
    $classCode = $class->getProperty('code')->getValue($promocode);
    $classPromocode = $class->getProperty('promocode')->getValue($promocode);

    expect($promocode)->toBeInstanceOf(\Zorb\Promocodes\Promocodes::class);
    expect($classCode)->toEqual($code);
    expect($classPromocode)->toBeNull();
});

it('should set actual code to variable and promocode instance', function () {
    $code = 'ABC-DEF';
    Promocode::factory()->code($code)->create();
    $promocode = Promocodes::code($code);

    $class = new ReflectionClass($promocode);
    $classCode = $class->getProperty('code')->getValue($promocode);
    $classPromocode = $class->getProperty('promocode')->getValue($promocode);

    expect($classCode)->toEqual($code);
    expect($classPromocode)->toBeInstanceOf(PromocodeContract::class);
    expect($classPromocode->code)->toBe($code);
});

it('should set user to variable', function () {
    $user = User::factory()->create();
    $promocode = Promocodes::user($user);

    $class = new ReflectionClass($promocode);
    $classUser = $class->getProperty('user')->getValue($promocode);

    expect($classUser)->toBe($user);
});

it('should throw exception when promocode not set', function () {
    Promocodes::apply();
})->throws(PromocodeDoesNotExistException::class);

it('should throw exception when promocode not found', function () {
    Promocodes::code('FOO-BAR')->apply();
})->throws(PromocodeDoesNotExistException::class);

it('should throw exception when promocode has no usages left', function () {
    $code = 'ABC-DEF';
    Promocode::factory()->code($code)->usagesLeft(0)->create();
    Promocodes::code($code)->apply();
})->throws(PromocodeNoUsagesLeftException::class);

it('should throw exception when promocode is expired', function () {
    $code = 'ABC-DEF';
    Promocode::factory()->code($code)->expired()->usagesLeft(2)->create();
    Promocodes::code($code)->apply();
})->throws(PromocodeExpiredException::class);

it('should throw exception when promocode is bound to user and no user is provided', function () {
    $code = 'ABC-DEF';
    Promocode::factory()->code($code)->notExpired()->boundToUser()->usagesLeft(2)->create();
    Promocodes::code($code)->apply();
})->throws(UserRequiredToAcceptPromocode::class);

it('should throw exception when promocode is bound to user and used by other user', function () {
    $code = 'ABC-DEF';

    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $promocode = Promocode::factory()->code($code)->notExpired()->boundToUser()->usagesLeft(2)->create();
    $promocode->user()->associate($user1);
    $promocode->save();

    Promocodes::code($code)->user($user2)->apply();
})->throws(PromocodeBoundToOtherUserException::class);

it('should throw exception when promocode is single used and trying to use second time', function () {
    $code = 'ABC-DEF';
    $user = User::factory()->create();

    Promocode::factory()->code($code)->notExpired()->multiUse(false)->usagesLeft(2)->create();
    Promocodes::code($code)->user($user)->apply();
    Promocodes::code($code)->user($user)->apply();
})->throws(PromocodeAlreadyUsedByUserException::class);

it('should throw exception if user model us not using trait', function () {
    $code = 'ABC-DEF';
    $user = UserWithoutTrait::factory()->create();

    Promocode::factory()->code($code)->notExpired()->usagesLeft(2)->create();
    Promocodes::code($code)->user($user)->apply();
})->throws(UserHasNoAppliesPromocodeTrait::class);

it('should create promocode-user association', function () {
    $code = 'ABC-DEF';
    $user = User::factory()->create();
    $promocode = Promocode::factory()->code($code)->notExpired()->usagesLeft(2)->create();

    Promocodes::code($code)->user($user)->apply();

    expect($promocode->users()->first()->id)->toEqual($user->id);
});

it('should create promocode-guest association', function () {
    $code = 'ABC-DEF';
    Promocode::factory()->code($code)->notExpired()->boundToUser(false)->usagesLeft(2)->create();
    Promocodes::code($code)->apply();

    expect(PromocodeUser::count())->toEqual(1);
    expect(PromocodeUser::first()->user_id)->toBeNull();
});

it('should decrement usages left on promocode', function () {
    $code = 'ABC-DEF';
    $promocode = Promocode::factory()->code($code)->notExpired()->boundToUser(false)->usagesLeft(2)->create();

    Promocodes::code($code)->apply();

    expect($promocode->fresh()->usages_left)->toEqual(1);
});

it('should return instance of promocode model', function () {
    $code = 'ABC-DEF';
    $promocode = Promocode::factory()->code($code)->notExpired()->boundToUser(false)->usagesLeft(2)->create();

    Promocodes::code($code)->apply();

    expect($promocode)->toBeInstanceOf(PromocodeContract::class);
});

it('should return generated codes', function () {
    $codes = Promocodes::count(3)->generate();

    expect($codes)->toBeCollection();
    expect($codes->count())->toEqual(3);
});

it('should generate code with custom mask', function () {
    $codes = Promocodes::mask('FOO-***-BAR')->generate();

    expect(Str::startsWith($codes->first(), 'FOO-'))->toBeTrue();
    expect(Str::endsWith($codes->first(), '-BAR'))->toBeTrue();
    expect(Str::length($codes->first()))->toEqual(11);
});

it('should generate code with custom characters', function () {
    $codes = Promocodes::mask('***')->characters('0')->generate();

    expect($codes->first())->toEqual('000');
});

it('should create codes in database', function () {
    Promocodes::count(3)->create();

    expect(Promocode::count())->toEqual(3);
});

it('should create code in database with custom mask', function () {
    Promocodes::mask('FOO-***-BAR')->create();

    $promocode = Promocode::first();

    expect(Str::startsWith($promocode->code, 'FOO-'))->toBeTrue();
    expect(Str::endsWith($promocode->code, '-BAR'))->toBeTrue();
    expect(Str::length($promocode->code))->toEqual(11);
});

it('should create code in database with custom characters', function () {
    Promocodes::mask('***')->characters('0')->create();

    $promocode = Promocode::first();

    expect($promocode->code)->toEqual('000');
});

it('should create code in database with user bounding', function () {
    Promocodes::boundToUser()->create();

    $promocode = Promocode::first();

    expect($promocode->bound_to_user)->toBeTrue();
});

it('should create code in database with unlimited usages', function () {
    Promocodes::unlimited()->create();

    $promocode = Promocode::first();

    expect($promocode->usages_left)->toEqual(-1);
});

it('should create code in database with multi use', function () {
    Promocodes::multiUse()->create();

    $promocode = Promocode::first();

    expect($promocode->multi_use)->toBeTrue();
});

it('should create code in database with custom usages left', function () {
    Promocodes::usages(5)->create();

    $promocode = Promocode::first();

    expect($promocode->usages_left)->toEqual(5);
});

it('should create code in database with details', function () {
    Promocodes::details(['foo' => 'bar'])->create();

    $promocode = Promocode::first();

    expect($promocode->details['foo'])->toEqual('bar');
});

it('should create code in database with expiration', function () {
    $expiration = Carbon::create(2022, 0, 0);
    Promocodes::expiration($expiration)->create();

    $promocode = Promocode::first();

    expect($promocode->expired_at->year)->toEqual($expiration->year);
    expect($promocode->expired_at->month)->toEqual($expiration->month);
    expect($promocode->expired_at->day)->toEqual($expiration->day);
});

it('should create code in database with user association', function () {
    $user = User::factory()->create();
    Promocodes::user($user)->create();

    $promocode = Promocode::first();

    expect($promocode->user->id)->toEqual($user->id);
});

it('should try to create unique code', function () {
    Promocode::factory()->code('AA')->create();
    Promocode::factory()->code('BB')->create();
    Promocode::factory()->code('AB')->create();
    Promocodes::mask('**')->characters('AB')->create();

    expect(Promocode::where('code', 'BA')->exists())->toBeTrue();
});
