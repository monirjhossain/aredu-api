<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'avatar_url' => $this->avatar_path ? url('storage/'.$this->avatar_path) : null,
            'is_active' => (bool) ($this->is_active ?? true),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')->values()),
            'permissions' => $this->whenLoaded('roles', function () {
                return $this->roles
                    ->flatMap(fn ($role) => $role->permissions->pluck('slug'))
                    ->unique()
                    ->values();
            }),
        ];
    }
}
