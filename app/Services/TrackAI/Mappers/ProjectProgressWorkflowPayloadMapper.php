<?php

namespace App\Services\TrackAI\Mappers;

use App\Models\ProjectProgressReport;

final class ProjectProgressWorkflowPayloadMapper
{
    /**
     * Build the executeWorkflow payload for a ProjectProgress report.
     *
     * @return array{workflowId: string, otherDetails: array<string, mixed>, payload: array<string, mixed>}
     */
    public function map(ProjectProgressReport $report): array
    {
        return [
            'workflowId' => config('saras.workflows.completion_id'),
            'otherDetails' => [
                'initiator' => 'INITIATOR_PROCESS',
                'processId' => $report->saras_process_id,
                'initiatorMeta' => [
                    'stageKey' => config('saras.workflows.completion_stage_key'),
                ],
            ],
            'payload' => [
                'engineersRemarks' => $report->remarks ?? '',
            ],
        ];
    }
}
