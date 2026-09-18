<?php

namespace Boy132\MinecraftModrinth;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;

class MinecraftModrinthPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'minecraft-modrinth';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Boy132\\MinecraftModrinth\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return config('minecraft-modrinth');
    }

    public function getSettingsForm(): array
    {
        return [
            Toggle::make('always_use_latest_version')
                ->label(trans('minecraft-modrinth::strings.settings.always_use_latest_version'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('minecraft-modrinth::strings.settings.always_use_latest_version_hint'))
                ->inline(false)
                ->default(fn () => config('minecraft-modrinth.always_use_latest_version')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'MINECRAFT_MODRINTH_ALWAYS_USE_LATEST_VERSION' => $data['always_use_latest_version'],
        ]);

        Notification::make()
            ->title(trans('minecraft-modrinth::strings.settings.settings_saved'))
            ->success()
            ->send();
    }
}
