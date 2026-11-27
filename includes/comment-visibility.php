<?php

/**
 * A reviewer comment is "addressed" once a newer file was uploaded into the
 * same document slot after the comment was written. Comments are never
 * deleted; this only filters what the applicant sees.
 */
function sql_comment_not_addressed(string $dc = 'dc', string $ad = 'ad'): string
{
    foreach ([$dc, $ad] as $alias) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Invalid SQL alias.');
        }
    }

    return "NOT EXISTS (
        SELECT 1
        FROM application_documents ad_newer
        WHERE ad_newer.application_id = {$ad}.application_id
          AND ad_newer.id <> {$ad}.id
          AND ad_newer.uploaded_at > {$dc}.created_at
          AND COALESCE(ad_newer.required_doc_id, 0) = COALESCE({$ad}.required_doc_id, 0)
          AND (COALESCE({$ad}.required_doc_id, 0) > 0
               OR ad_newer.document_type = {$ad}.document_type)
    )";
}
