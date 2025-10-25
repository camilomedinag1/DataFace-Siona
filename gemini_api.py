#!/usr/bin/env python3
"""
API para comunicarse con Gemini AI
Acepta requests POST con JSON que contiene 'mensaje' y 'datos'
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
import google.generativeai as genai
import json
import sys

app = Flask(__name__)
CORS(app)  # Permitir CORS desde cualquier origen

# Configurar Gemini API
GEMINI_API_KEY = 'AIzaSyBZQacoU5j2MqKCOv_QFdLAO8_rN3eINyk'
genai.configure(api_key=GEMINI_API_KEY)

@app.route('/chat', methods=['POST'])
def chat():
    try:
        data = request.get_json()
        
        if not data or 'mensaje' not in data:
            return jsonify({'error': 'Mensaje no proporcionado'}), 400
        
        mensaje = data['mensaje']
        datos_empleados = data.get('datos', '[]')
        
        # Preparar el prompt
        prompt = f"""Eres un agente el cual se le pueden hacer preguntas de entradas y salidas de los empleados de la empresa. La llegada tarde es después de las 8:10 AM y la salida es a las 5 PM. La información es la siguiente:

{datos_empleados}

Usuario pregunta: {mensaje}"""
        
        # Llamar a Gemini
        model = genai.GenerativeModel('gemini-pro-latest')
        response = model.generate_content(prompt)
        
        # Extraer el texto de la respuesta
        respuesta_texto = response.text
        
        return jsonify({
            'respuesta': respuesta_texto,
            'timestamp': json.dumps(request.headers.get('Timestamp', ''))
        })
        
    except Exception as e:
        print(f"Error: {str(e)}", file=sys.stderr)
        return jsonify({'error': f'Error interno: {str(e)}'}), 500

@app.route('/health', methods=['GET'])
def health():
    return jsonify({'status': 'ok', 'service': 'gemini-api'})

if __name__ == '__main__':
    print("Iniciando servidor Flask en puerto 5000...")
    app.run(host='0.0.0.0', port=5000, debug=False)
