<?php

namespace Boy132\PlayerCounter\Extensions\Query\Schemas;

class MinecraftProxyQueryTypeSchema extends MinecraftJavaQueryTypeSchema
{
    public function getId(): string
    {
        return 'minecraft_proxy';
    }

    public function getName(): string
    {
        return 'Minecraft (Proxy)';
    }
}
