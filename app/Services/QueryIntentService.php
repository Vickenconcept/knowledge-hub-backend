<?php

namespace App\Services;

class QueryIntentService
{
    /**
     * Detect the intent/type of the user's query
     */
    public function detectIntent(string $query): array
    {
        $queryLower = strtolower($query);
        
        $intents = [];
        
        // Skills-related queries
        if (preg_match('/(skill|technology|tech stack|know|proficient|expert)/i', $query)) {
            $intents[] = 'skills';
        }
        
        // Experience/work history queries
        if (preg_match('/(experience|work|job|position|role|worked|employment)/i', $query)) {
            $intents[] = 'experience';
        }
        
        // Project queries
        if (preg_match('/(project|built|developed|created|designed|portfolio)/i', $query)) {
            $intents[] = 'projects';
        }
        
        // Education queries
        if (preg_match('/(education|degree|university|college|certification|trained)/i', $query)) {
            $intents[] = 'education';
        }
        
        // Contact/personal info
        if (preg_match('/(contact|email|phone|address|reach|linkedin|github)/i', $query)) {
            $intents[] = 'contact';
        }
        
        // Summary/overview queries
        if (preg_match('/(summary|overview|about|tell me|who is|profile)/i', $query)) {
            $intents[] = 'summary';
        }
        
        // Comparison queries
        if (preg_match('/(compare|versus|vs|difference|better|similar)/i', $query)) {
            $intents[] = 'comparison';
        }
        
        // List/enumerate queries
        if (preg_match('/(list|all|every|enumerate|what are)/i', $query)) {
            $intents[] = 'list';
        }
        
        // Financial queries
        if (preg_match('/(price|cost|invoice|payment|budget|revenue)/i', $query)) {
            $intents[] = 'financial';
        }
        
        // Timeline/temporal queries
        if (preg_match('/(when|timeline|history|latest|recent|last|first)/i', $query)) {
            $intents[] = 'timeline';
        }
        
        // Default
        if (empty($intents)) {
            $intents[] = 'general';
        }
        
        return [
            'primary_intent' => $intents[0] ?? 'general',
            'all_intents' => $intents,
            'is_list_query' => in_array('list', $intents),
            'is_comparison' => in_array('comparison', $intents),
            'is_detailed' => in_array('summary', $intents) || in_array('list', $intents),
        ];
    }
    
    /**
     * Get formatting instructions based on intent
     */
    public function getFormattingInstructions(array $intent, array $documentTypes): string
    {
        $primary = $intent['primary_intent'];
        $instructions = "";
        
        switch ($primary) {
            case 'skills':
                $instructions .= "SKILLS QUERY DETECTED:\n";
                $instructions .= "Structure your answer to clearly enumerate:\n";
                $instructions .= "1. Technical Skills (Frontend, Backend, Databases, etc.)\n";
                $instructions .= "2. Design/UI-UX Skills (if applicable)\n";
                $instructions .= "3. DevOps/Infrastructure Skills\n";
                $instructions .= "4. Soft Skills and Methodologies\n";
                $instructions .= "For each skill, mention proficiency level if stated in the documents.\n\n";
                break;
                
            case 'experience':
                $instructions .= "EXPERIENCE QUERY DETECTED:\n";
                $instructions .= "Structure your answer chronologically (most recent first):\n";
                $instructions .= "For each role, include:\n";
                $instructions .= "- Company/Organization name\n";
                $instructions .= "- Position/Title\n";
                $instructions .= "- Duration (dates)\n";
                $instructions .= "- Key responsibilities and achievements\n";
                $instructions .= "- Technologies used\n\n";
                break;
                
            case 'projects':
                $instructions .= "PROJECTS QUERY DETECTED:\n";
                $instructions .= "For each project, describe:\n";
                $instructions .= "- Project name and purpose\n";
                $instructions .= "- Technologies and tools used\n";
                $instructions .= "- Key features or outcomes\n";
                $instructions .= "- Impact or results (metrics if available)\n\n";
                break;
                
            case 'education':
                $instructions .= "EDUCATION QUERY DETECTED:\n";
                $instructions .= "List academic and professional development:\n";
                $instructions .= "- Degrees and institutions\n";
                $instructions .= "- Certifications and training\n";
                $instructions .= "- Relevant coursework or specializations\n";
                $instructions .= "- Dates and honors (if mentioned)\n\n";
                break;
                
            case 'financial':
                $instructions .= "FINANCIAL QUERY DETECTED:\n";
                $instructions .= "Be precise with:\n";
                $instructions .= "- Exact amounts and currencies\n";
                $instructions .= "- Payment dates and terms\n";
                $instructions .= "- Line items and totals\n";
                $instructions .= "- Any conditions or notes\n\n";
                break;
                
            case 'comparison':
                $instructions .= "COMPARISON QUERY DETECTED:\n";
                $instructions .= "Structure as a clear comparison:\n";
                $instructions .= "- Identify the items being compared\n";
                $instructions .= "- List similarities\n";
                $instructions .= "- List differences\n";
                $instructions .= "- Provide a balanced conclusion\n\n";
                break;
                
            case 'summary':
                $instructions .= "SUMMARY/OVERVIEW QUERY DETECTED:\n";
                $instructions .= "Provide a comprehensive overview covering:\n";
                $instructions .= "- Professional identity and expertise\n";
                $instructions .= "- Key strengths and capabilities\n";
                $instructions .= "- Notable achievements\n";
                $instructions .= "- Relevant background or context\n\n";
                break;
                
            default:
                $instructions .= "GENERAL QUERY DETECTED:\n";
                $instructions .= "Provide a clear, well-organized answer that directly addresses the question.\n\n";
                break;
        }
        
        return $instructions;
    }
}

