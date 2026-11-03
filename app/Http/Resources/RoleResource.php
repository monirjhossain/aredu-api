<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_system' => $this->isSystem(),
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('slug')->values(),
            ),
        ];
    }
}
