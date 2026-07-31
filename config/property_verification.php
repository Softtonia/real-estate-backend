<?php

return [
    'property_post_type_slug' => 'property-listing',

    /*
     * These roles can submit properties from the frontend.
     * Reviewer roles are intentionally NOT configured here.
     */
    'submission_roles' => [
        'owner',
        'company',
        'agent',
        'consultancy',
    ],

    /*
     * Any role becomes a reviewer role dynamically when it receives all
     * permissions listed below. Agent, Manager, Staff or a custom role can
     * therefore be used without changing PHP code.
     */
    'required_verifier_permissions' => [
        'property_verifications.review',
        'property_verifications.approve',
        'property_verifications.reject',
    ],

    'permissions' => [
        'property_verifications.read',
        'property_verifications.assign',
        'property_verifications.review',
        'property_verifications.approve',
        'property_verifications.reject',
    ],
];
