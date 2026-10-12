<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    private const ABILITIES = [
        'football.view' => ['action' => 'read', 'subject' => 'Football'],
        'football.manage' => ['action' => 'manage', 'subject' => 'Football'],
        'administration.manage' => ['action' => 'manage', 'subject' => 'Administration'],
    ];

    public function toArray(Request $request): array
    {
        $permissions = $this->resource->permissionCodes();
        $abilities = [];
        foreach ($permissions as $permission) {
            if (isset(self::ABILITIES[$permission])) {
                $abilities[] = self::ABILITIES[$permission];
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'roles' => $this->resource->roles()->where('is_active', true)->pluck('code'),
            'permissions' => $permissions,
            'abilities' => $abilities,
        ];
    }
}
