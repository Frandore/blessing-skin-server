<?php

use App\Models\User;
use App\Models\Player;
use App\Models\Texture;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class TextureControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function testJson()
    {
        $steve = factory(Texture::class)->create();

        // Player is not existed
        $this->get('/nope.json')
            ->see(trans('general.unexistent-player'))
            ->assertResponseStatus(404);

        // Player is banned
        $player = factory(Player::class)->create(['tid_steve' => $steve->tid]);
        User::find($player->uid)->setPermission(User::BANNED);
        $this->get("/{$player->player_name}.json")
            ->see(trans('general.player-banned'))
            ->assertResponseStatus(403);

        User::find($player->uid)->setPermission(User::NORMAL);

        // Default API is CSL API
        $this->get("/{$player->player_name}.json")
            ->seeJson([
                'username' => $player->player_name,
                'skins' => [
                    'default' => $steve->hash,
                    'slim' => null
                ],
                'cape' => null
            ])->seeHeader('Last-Modified');
    }

    public function testJsonWithApi()
    {
        $steve = factory(Texture::class)->create();
        $player = factory(Player::class)->create(['tid_steve' => $steve->tid]);

        // CSL API
        $this->get("/csl/{$player->player_name}.json")
            ->seeJson([
                'username' => $player->player_name,
                'skins' => [
                    'default' => $steve->hash,
                    'slim' => null
                ],
                'cape' => null
            ])->seeHeader('Last-Modified');

        // USM API
        $this->get("/usm/{$player->player_name}.json")
            ->seeJson([
                'player_name' => $player->player_name,
                'model_preference' => ['default', 'slim'],
                'skins' => [
                    'default' => $steve->hash,
                    'slim' => null
                ],
                'cape' => null
            ])->seeHeader('Last-Modified');
    }

    public function testTexture()
    {
        $steve = factory(Texture::class)->create();
        Storage::disk('textures')->put($steve->hash, '');
        $this->get('/textures/nope')
            ->see('404');

        $this->get('/textures/'.$steve->hash)
            ->seeHeader('Content-Type', 'image/png')
            ->seeHeader('Last-Modified')
            ->seeHeader('Accept-Ranges', 'bytes')
            ->seeHeader('Content-Length', Storage::disk('textures')->size($steve->hash))
            ->assertResponseStatus(200);
    }

    public function testTextureWithApi()
    {
        $steve = factory(Texture::class)->create();
        Storage::disk('textures')->put($steve->hash, '');

        $this->get('/csl/textures/'.$steve->hash)
            ->seeHeader('Content-Type', 'image/png')
            ->seeHeader('Last-Modified')
            ->seeHeader('Accept-Ranges', 'bytes')
            ->seeHeader('Content-Length', Storage::disk('textures')->size($steve->hash))
            ->assertResponseStatus(200);

        $this->get('/usm/textures/'.$steve->hash)
            ->seeHeader('Content-Type', 'image/png')
            ->seeHeader('Last-Modified')
            ->seeHeader('Accept-Ranges', 'bytes')
            ->seeHeader('Content-Length', Storage::disk('textures')->size($steve->hash))
            ->assertResponseStatus(200);
    }

    public function testSkin()
    {
        $steve = factory(Texture::class)->create();
        $player = factory(Player::class)->create([
            'preference' => 'slim',
            'tid_steve' => $steve->tid,
            'tid_alex' => 0
        ]);

        $this->get("/skin/{$player->player_name}.png")
            ->see(trans('general.texture-not-uploaded', ['type' => 'alex']));

        $player->setPreference('default');
        $this->get("/skin/{$player->player_name}.png")
            ->see(trans('general.texture-deleted'));

        Storage::disk('textures')->put($steve->hash, '');
        $this->get("/skin/{$player->player_name}.png")
            ->seeHeader('Content-Type', 'image/png')
            ->seeHeader('Last-Modified')
            ->seeHeader('Accept-Ranges', 'bytes')
            ->seeHeader('Content-Length', Storage::disk('textures')->size($steve->hash))
            ->assertResponseStatus(200);
    }

    public function testSkinWithModel()
    {
        $steve = factory(Texture::class)->create();
        $player = factory(Player::class)->create([
            'preference' => 'slim',
            'tid_steve' => $steve->tid,
            'tid_alex' => 0
        ]);

        $this->get("/skin/alex/{$player->player_name}.png")
            ->see(trans('general.texture-not-uploaded', ['type' => 'alex']));

        $player->setPreference('default');
        $this->get("/skin/steve/{$player->player_name}.png")
            ->see(trans('general.texture-deleted'));
    }

    public function testCape()
    {
        $cape = factory(Texture::class, 'cape')->create();
        $player = factory(Player::class)->create([
            'tid_cape' => $cape->tid
        ]);

        $this->get("/cape/{$player->player_name}.png")
            ->see(trans('general.texture-deleted'));
    }

    public function testAvatar()
    {
        $base64_email = base64_encode('a@b.c');
        $this->get("/avatar/$base64_email.png")
            ->seeHeader('Content-Type', 'image/png');

        $steve = factory(Texture::class)->create();
        $png = base64_decode(\App\Http\Controllers\TextureController::getDefaultSteveSkin());
        Storage::disk('textures')->put($steve->hash, $png);

        $user = factory(User::class)->create(['avatar' => $steve->tid]);

        $mock = Mockery::mock('overload:Minecraft');
        $mock->shouldReceive('generateAvatarFromSkin')
            ->once()
            ->andReturn(imagecreatefromstring($png));

        $this->expectsEvents(\App\Events\GetAvatarPreview::class);
        $this->get('/avatar/'.base64_encode($user->email).'.png')
            ->seeHeader('Content-Type', 'image/png');
    }

    public function testAvatarWithSize()
    {
        $steve = factory(Texture::class)->create();
        $png = base64_decode(\App\Http\Controllers\TextureController::getDefaultSteveSkin());
        Storage::disk('textures')->put($steve->hash, $png);

        $user = factory(User::class)->create(['avatar' => $steve->tid]);

        $mock = Mockery::mock('overload:Minecraft');
        $mock->shouldReceive('generateAvatarFromSkin')
            ->once()
            ->andReturn(imagecreatefromstring($png));

        $this->expectsEvents(\App\Events\GetAvatarPreview::class);
        $this->get('/avatar/50/'.base64_encode($user->email).'.png')
            ->seeHeader('Content-Type', 'image/png');
    }

    public function testPreview()
    {
        $steve = factory(Texture::class)->create();
        $cape = factory(Texture::class, 'cape')->create();

        $this->get('/preview/0.png')
            ->seeHeader('Content-Type', 'image/png');

        $this->get("/preview/{$steve->tid}.png")
            ->seeHeader('Content-Type', 'image/png');

        $png = base64_decode(\App\Http\Controllers\TextureController::getDefaultSteveSkin());
        Storage::disk('textures')->put($steve->hash, $png);
        Storage::disk('textures')->put($cape->hash, $png);

        $mock = Mockery::mock('overload:Minecraft');
        $mock->shouldReceive('generatePreviewFromSkin')
            ->once()
            ->andReturn(imagecreatefromstring($png));
        $this->expectsEvents(\App\Events\GetSkinPreview::class);
        $this->get("/preview/{$steve->tid}.png")
            ->seeHeader('Content-Type', 'image/png');

        $mock->shouldReceive('generatePreviewFromCape')
            ->once()
            ->andReturn(imagecreatefromstring($png));
        $this->get("/preview/{$cape->tid}.png")
            ->seeHeader('Content-Type', 'image/png');
    }

    public function testPreviewWithSize()
    {
        $this->get('/preview/200/0.png')
            ->seeHeader('Content-Type', 'image/png');
    }

    public function testRaw()
    {
        $steve = factory(Texture::class)->create();
        Storage::disk('textures')->put($steve->hash, '');

        // Not found
        $this->get('/raw/0.png')
            ->see(trans('skinlib.non-existent'));

        // Success
        $this->get("/raw/{$steve->tid}.png")
            ->seeHeader('Content-Type', 'image/png');

        // Texture is deleted
        Storage::disk('textures')->delete($steve->hash);
        $this->get("/raw/{$steve->tid}.png")
            ->assertResponseStatus(404);
    }
}
