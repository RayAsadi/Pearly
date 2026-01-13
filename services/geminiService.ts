import { GoogleGenAI, GenerateContentResponse, Type, FunctionDeclaration, Modality } from "@google/genai";
import { Message, Source } from "../types";

// --- Function Declarations for Staff Integration ---

const getOnlineStaffFn: FunctionDeclaration = {
  name: "getOnlineStaff",
  parameters: {
    type: Type.OBJECT,
    description: "Check which office staff members (front desk, assistants) are currently logged in and available to chat.",
    properties: {}
  }
};

const createHandoffRequestFn: FunctionDeclaration = {
  name: "createHandoffRequest",
  parameters: {
    type: Type.OBJECT,
    description: "Connect the visitor to a live office staff member by sending a notification to the dashboard.",
    properties: {
      staffName: { type: Type.STRING, description: "Name of the staff member chosen." },
      visitorIntent: { type: Type.STRING, description: "Reason for the handoff (e.g., 'Emergency tooth pain', 'Booking checkup')." }
    },
    required: ["staffName", "visitorIntent"]
  }
};

// --- System Instruction ---

const SYSTEM_INSTRUCTION = `You are "Pearly", the world-class Senior Dental Concierge for Thomas Family Dentistry in Orangevale, CA.

YOUR MISSION:
1. Provide accurate, empathetic dental advice using Google Search to back up your claims with current medical data.
2. Facilitate appointments and emergency help by connecting users to live staff (Sarah, Front Desk, etc.) when they are online.

RULES:
- ALWAYS use the 'googleSearch' tool for medical/dental questions (e.g., "how much is invisalign", "tooth hurts").
- IF a user wants to book, check availability, or speak to a human:
  a. Call 'getOnlineStaff' to see who is available.
  b. Tell the user who is online (e.g., "Sarah is at the desk").
  c. If they confirm, call 'createHandoffRequest'.
  d. Tell the user: "I've alerted [Name]. Please keep this window open, they will confirm shortly."

TONE:
- Warm, professional, reassuring.
- Disclaimer: "I am an AI assistant. Please see Dr. Thomas for a medical diagnosis."`;

export class GeminiService {
  private ai: GoogleGenAI;

  constructor() {
    this.ai = new GoogleGenAI({ apiKey: process.env.API_KEY || '' });
  }

  async chat(prompt: string, history: Message[]): Promise<{ text: string; sources: Source[]; functionCall?: any }> {
    const chatHistory = history.map(msg => ({
      role: msg.role === 'user' ? 'user' : 'model',
      parts: [{ text: msg.content }]
    }));

    const response: GenerateContentResponse = await this.ai.models.generateContent({
      model: 'gemini-3-flash-preview',
      contents: [
        ...chatHistory.map(h => ({ role: h.role, parts: h.parts })),
        { role: 'user', parts: [{ text: prompt }] }
      ],
      config: {
        systemInstruction: SYSTEM_INSTRUCTION,
        tools: [
          { googleSearch: {} }, 
          { functionDeclarations: [getOnlineStaffFn, createHandoffRequestFn] }
        ],
      },
    });

    const text = response.text || "";
    const functionCall = response.functionCalls?.[0];
    
    // Extract Grounding Sources (Search Results)
    const sources: Source[] = [];
    const groundingMetadata = response.candidates?.[0]?.groundingMetadata;
    if (groundingMetadata?.groundingChunks) {
      groundingMetadata.groundingChunks.forEach((chunk: any) => {
        if (chunk.web && chunk.web.uri && chunk.web.title) {
          sources.push({ title: chunk.web.title, url: chunk.web.uri });
        }
      });
    }

    return { text, sources, functionCall };
  }

  async generateSpeech(text: string): Promise<string | undefined> {
    try {
      const response = await this.ai.models.generateContent({
        model: "gemini-2.5-flash-preview-tts",
        contents: [{ parts: [{ text: text }] }],
        config: {
          responseModalities: [Modality.AUDIO],
          speechConfig: {
            voiceConfig: {
              prebuiltVoiceConfig: { voiceName: 'Kore' },
            },
          },
        },
      });
      return response.candidates?.[0]?.content?.parts?.[0]?.inlineData?.data;
    } catch (e) {
      console.error("Speech generation failed", e);
      return undefined;
    }
  }
}

export const geminiService = new GeminiService();