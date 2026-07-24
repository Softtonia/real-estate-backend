<?php

namespace Database\Seeders;

use App\Models\KycDocument;
use App\Models\KycRoleRule;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KycRoleRuleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            Role::query()
                ->select(['id', 'name'])
                ->orderBy('id')
                ->chunkById(100, function ($roles) {
                    foreach ($roles as $role) {
                        $normalizedRole = $this->normalizeRoleName($role->name);

                        if ($this->isAdminRole($normalizedRole)) {
                            continue;
                        }

                        $rule = $this->ruleForRole($normalizedRole);

                        KycRoleRule::query()->updateOrCreate(
                            [
                                'role_id' => $role->id,
                            ],
                            [
                                'requires_kyc' => $rule['requires_kyc'],
                                'can_publish_without_kyc' => $rule['can_publish_without_kyc'],
                                'required_documents' => $rule['required_documents'],
                                'is_active' => true,
                                'notes' => $rule['notes'],
                            ]
                        );
                    }
                });
        });
    }

    private function ruleForRole(string $role): array
    {
        if ($this->isOwnerRole($role)) {
            return [
                'requires_kyc' => false,
                'can_publish_without_kyc' => true,
                'required_documents' => [
                    KycDocument::TYPE_AADHAAR_FRONT,
                    KycDocument::TYPE_AADHAAR_BACK,
                ],
                'notes' => 'Owner role is allowed to publish without approved KYC.',
            ];
        }

        if ($this->isBusinessKycRole($role)) {
            return [
                'requires_kyc' => true,
                'can_publish_without_kyc' => false,
                'required_documents' => [
                    KycDocument::TYPE_AADHAAR_FRONT,
                    KycDocument::TYPE_AADHAAR_BACK,
                    KycDocument::TYPE_BUSINESS_PROOF,
                ],
                'notes' => 'Business real estate role must complete KYC before publishing listings.',
            ];
        }

        return [
            'requires_kyc' => false,
            'can_publish_without_kyc' => true,
            'required_documents' => [],
            'notes' => 'Default role rule. KYC is not required unless enabled by admin.',
        ];
    }

    private function normalizeRoleName(?string $name): string
    {
        $name = strtolower(trim((string) $name));

        return str_replace([' ', '_', '-'], '', $name);
    }

    private function isAdminRole(string $role): bool
    {
        return in_array($role, [
            'admin',
            'administrator',
            'superadmin',
            'superadministrator',
        ], true);
    }

    private function isOwnerRole(string $role): bool
    {
        return in_array($role, [
            'owner',
            'propertyowner',
            'landowner',
        ], true);
    }

    private function isBusinessKycRole(string $role): bool
    {
        return in_array($role, [
            'agent',
            'realestateagent',
            'broker',

            'consultancy',
            'consultant',
            'propertyconsultant',
            'realestateconsultant',

            'company',
            'realestatecompany',
            'agency',

            'developer',
            'builder',
            'builderdeveloper',
        ], true);
    }
}