# Known dynamic keys that regex can't find (app-specific, add as needed)
# Format: string, or (string, explicit_location) for keys not found as literals in source
DYNAMIC_KEYS = [
    ('senderpersonmodalTitle', 'src/itsl/components/modals/PersonReferenceIDModalItsl.vue:79'),
    ('senderreferencemodalTitle', 'src/itsl/components/modals/PersonReferenceIDModalItsl.vue:79'),
    ('recipientpersonmodalTitle', 'src/itsl/components/modals/PersonReferenceIDModalItsl.vue:79'),
    ('recipientreferencemodalTitle', 'src/itsl/components/modals/PersonReferenceIDModalItsl.vue:79'),
    # Strings in ternary expressions that regex can't parse
    'Could not mark as read',
    'Could not mark as unread',
]
