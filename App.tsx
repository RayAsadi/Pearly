import React, { useState, useRef, useEffect } from 'react';
import { Message, ChatState } from './types';
import { geminiService } from './services/geminiService';
import MessageBubble from './components/MessageBubble';
import ChatInput from './components/ChatInput';

const App: React.FC = () => {
  const [state, setState] = useState<ChatState>({
    messages: [
      {
        id: '1',
        role: 'assistant',
        content: "Hi! I'm Pearly. 🦷 I can answer your dental questions or connect you directly with our front desk team in real-time. How can I help you?",
        timestamp: Date.now(),
      }
    ],
    isLoading: false,
    error: null,
  });

  const [pollingId, setPollingId] = useState<string | null>(null);
  const [isVoiceEnabled, setIsVoiceEnabled] = useState(false);
  const [isSpeaking, setIsSpeaking] = useState(false);
  const scrollRef = useRef<HTMLDivElement>(null);
  const audioContextRef = useRef<AudioContext | null>(null);

  useEffect(() => {
    if (scrollRef.current) {
      scrollRef.current.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
    }
  }, [state.messages, state.isLoading]);

  const initAudio = () => {
    if (!audioContextRef.current) {
      audioContextRef.current = new (window.AudioContext || (window as any).webkitAudioContext)({ sampleRate: 24000 });
    }
    if (audioContextRef.current.state === 'suspended') {
      audioContextRef.current.resume();
    }
  };

  const decodeBase64 = (base64: string) => {
    const binaryString = atob(base64);
    const bytes = new Uint8Array(binaryString.length);
    for (let i = 0; i < binaryString.length; i++) {
      bytes[i] = binaryString.charCodeAt(i);
    }
    return bytes;
  };

  const decodeAudioData = async (data: Uint8Array, ctx: AudioContext): Promise<AudioBuffer> => {
    const dataInt16 = new Int16Array(data.buffer);
    const frameCount = dataInt16.length;
    const buffer = ctx.createBuffer(1, frameCount, 24000);
    const channelData = buffer.getChannelData(0);
    for (let i = 0; i < frameCount; i++) {
      channelData[i] = dataInt16[i] / 32768.0;
    }
    return buffer;
  };

  const playSpeech = async (text: string) => {
    if (!isVoiceEnabled) return;
    initAudio();
    const ctx = audioContextRef.current!;
    const base64Audio = await geminiService.generateSpeech(text);
    if (base64Audio) {
      try {
        const audioBuffer = await decodeAudioData(decodeBase64(base64Audio), ctx);
        const source = ctx.createBufferSource();
        source.buffer = audioBuffer;
        source.connect(ctx.destination);
        source.onended = () => setIsSpeaking(false);
        setIsSpeaking(true);
        source.start();
      } catch (e) {
        console.error("Playback error:", e);
        setIsSpeaking(false);
      }
    }
  };

  // Polling for staff response
  useEffect(() => {
    let interval: any;
    if (pollingId) {
      interval = setInterval(async () => {
        try {
          const res = await fetch(`https://roidentalagency.com/thomas/api_staff.php?action=check_status&request_id=${pollingId}`);
          const data = await res.json();
          if (data.status === 'completed') {
            setPollingId(null);
            clearInterval(interval);
            const msg = `✅ Confirmed! ${data.staff_name} has accepted your request. They are ready for you.`;
            addAssistantMessage(msg);
            playSpeech(msg);
          } else if (data.status === 'rejected') {
            setPollingId(null);
            clearInterval(interval);
            const msg = `Front desk update: That specific slot is unavailable, but they've suggested: ${data.staff_response || 'an alternative time'}. Does that work?`;
            addAssistantMessage(msg);
            playSpeech(msg);
          }
        } catch (e) {
          console.error("Polling error", e);
        }
      }, 3000);
    }
    return () => clearInterval(interval);
  }, [pollingId, isVoiceEnabled]);

  const addAssistantMessage = (content: string) => {
    setState(prev => ({
      ...prev,
      messages: [...prev.messages, { id: Date.now().toString(), role: 'assistant', content, timestamp: Date.now() }]
    }));
  };

  const handleSendMessage = async (content: string) => {
    const userMessage: Message = { id: Date.now().toString(), role: 'user', content, timestamp: Date.now() };
    setState(prev => ({ ...prev, messages: [...prev.messages, userMessage], isLoading: true, error: null }));

    try {
      const response = await geminiService.chat(content, state.messages);
      
      if (response.functionCall) {
        if (response.functionCall.name === 'getOnlineStaff') {
          const staffRes = await fetch("https://roidentalagency.com/thomas/api_staff.php?action=get_online_staff");
          const staff = await staffRes.json();
          const staffList = staff.map((s: any) => `${s.first_name} (${s.role})`).join(", ");
          const reply = staff.length > 0 
            ? `I see ${staff.length} team members online: ${staffList}. Who would you like to chat with?`
            : "Actually, it looks like our team is currently helping other patients. Would you like to leave a callback number?";
          addAssistantMessage(reply);
          playSpeech(reply);
        } else if (response.functionCall.name === 'createHandoffRequest') {
          const formData = new FormData();
          formData.append('action', 'create_request');
          formData.append('type', 'chat_request');
          formData.append('visitor_data', JSON.stringify({ name: "Visitor", intent: response.functionCall.args.visitorIntent }));
          const res = await fetch("https://roidentalagency.com/thomas/api_staff.php", { method: 'POST', body: formData });
          const data = await res.json();
          setPollingId(data.request_id);
          const reply = `Pinging ${response.functionCall.args.staffName} now... please wait a few seconds.`;
          addAssistantMessage(reply);
          playSpeech(reply);
        }
      } else {
        const assistantMessage: Message = {
          id: (Date.now() + 1).toString(),
          role: 'assistant',
          content: response.text,
          timestamp: Date.now(),
          sources: response.sources
        };
        setState(prev => ({ ...prev, messages: [...prev.messages, assistantMessage], isLoading: false }));
        playSpeech(response.text);
      }
    } catch (error) {
      setState(prev => ({ ...prev, isLoading: false, error: "Service busy. Please try again." }));
    } finally {
      setState(prev => ({ ...prev, isLoading: false }));
    }
  };

  return (
    <div className="flex flex-col h-screen bg-slate-50 overflow-hidden">
      <header className="bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between sticky top-0 z-10">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 bg-[#00d2d3] rounded-full flex items-center justify-center text-white shadow-lg relative">
            🦷
            {isSpeaking && (
              <span className="absolute -top-1 -right-1 flex h-4 w-4">
                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                <span className="relative inline-flex rounded-full h-4 w-4 bg-blue-500"></span>
              </span>
            )}
          </div>
          <div>
            <h1 className="text-lg font-bold text-slate-900 leading-none">Pearly Concierge</h1>
            <p className="text-[10px] text-slate-400 uppercase font-semibold tracking-wider">Voice Enabled AI</p>
          </div>
        </div>
        <button 
          onClick={() => {
            initAudio();
            setIsVoiceEnabled(!isVoiceEnabled);
          }}
          className={`p-2 rounded-full transition-all ${isVoiceEnabled ? 'bg-blue-100 text-blue-600' : 'bg-slate-100 text-slate-400'}`}
          title={isVoiceEnabled ? "Mute Pearly" : "Unmute Pearly"}
        >
          {isVoiceEnabled ? (
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" className="w-6 h-6">
              <path d="M13.5 4.06c0-1.336-1.616-2.005-2.56-1.06l-4.5 4.5H4.508c-1.141 0-2.063.922-2.063 2.063v3.012c0 1.141.922 2.063 2.063 2.063h1.932l4.5 4.5c.944.945 2.56.276 2.56-1.06V4.06zM18.54 5.44a.75.75 0 011.06 0 11.25 11.25 0 010 15.908.75.75 0 01-1.06-1.06 9.75 9.75 0 000-13.788.75.75 0 010-1.06zm-4.242 4.242a.75.75 0 011.06 0 5.25 5.25 0 010 7.424.75.75 0 01-1.06-1.06 3.75 3.75 0 000-5.304.75.75 0 010-1.06z" />
            </svg>
          ) : (
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" className="w-6 h-6">
              <path d="M13.5 4.06c0-1.336-1.616-2.005-2.56-1.06l-4.5 4.5H4.508c-1.141 0-2.063.922-2.063 2.063v3.012c0 1.141.922 2.063 2.063 2.063h1.932l4.5 4.5c.944.945 2.56.276 2.56-1.06V4.06zM17.78 9.22a.75.75 0 10-1.06 1.06L18.44 12l-1.72 1.72a.75.75 0 001.06 1.06l1.72-1.72 1.72 1.72a.75.75 0 101.06-1.06L20.56 12l1.72-1.72a.75.75 0 00-1.06-1.06l-1.72 1.72-1.72-1.72z" />
            </svg>
          )}
        </button>
      </header>
      <main ref={scrollRef} className="flex-1 overflow-y-auto p-4 md:p-6 custom-scrollbar">
        <div className="max-w-4xl mx-auto">
          {state.messages.map((msg) => <MessageBubble key={msg.id} message={msg} />)}
          {state.isLoading && <div className="text-sm text-slate-400 italic flex items-center gap-2">
            <span className="w-1.5 h-1.5 bg-slate-300 rounded-full animate-bounce"></span>
            <span className="w-1.5 h-1.5 bg-slate-300 rounded-full animate-bounce delay-75"></span>
            <span className="w-1.5 h-1.5 bg-slate-300 rounded-full animate-bounce delay-150"></span>
            Pearly is checking resources...
          </div>}
        </div>
      </main>
      <ChatInput onSendMessage={handleSendMessage} disabled={state.isLoading} />
    </div>
  );
};

export default App;